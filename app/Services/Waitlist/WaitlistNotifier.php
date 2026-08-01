<?php

declare(strict_types=1);

namespace App\Services\Waitlist;

use App\Jobs\SendSms;
use App\Models\CycleDay;
use App\Models\WaitlistEntry;
use App\Services\Ordering\CycleGate;
use App\Support\GhanaPhone;

/**
 * Tell the people waiting that a portion came back.
 *
 * ⚠️⚠️ TWO HONEST LIMITATIONS, STATED HERE RATHER THAN DISCOVERED LATER. ⚠️⚠️
 *
 * **1. This is first-come, not a reservation.** When three portions free up, the first three
 * people waiting are texted, and nothing holds a portion for them. Somebody browsing the
 * site at that moment can take one before they open the message.
 *
 * The alternative is a claim window — hold each portion for its person for fifteen minutes —
 * and it is the wrong trade for this business. It means capacity sitting idle while somebody
 * asleep does not read a text, on a day that is already sold out, for food being cooked
 * tomorrow. The message says "first come" so the promise made matches the promise kept.
 *
 * **2. Nobody is texted about a day they can no longer order for.** The gate is re-checked
 * per day before any message goes out. A portion freeing up two hours after the cutoff is a
 * portion nobody can buy, and texting somebody about it produces a phone call rather than a
 * sale — the single most annoying possible message to receive.
 */
final class WaitlistNotifier
{
    public function __construct(private readonly CycleGate $gate) {}

    /**
     * A portion came back on this day. Text whoever is next.
     *
     * Called after a cancellation returns capacity, and after she raises a cap. `$menuItemId`
     * narrows to the dish that freed up; entries with a null `menu_item_id` want anything
     * that day and are notified either way.
     *
     * @return int how many were texted
     */
    public function capacityFreed(CycleDay $day, ?int $menuItemId, int $portions): int
    {
        if ($portions < 1) {
            return 0;
        }

        // ⚠️ Limitation 2. Reload so the gate reads current capacity rather than whatever
        // the caller was holding before it changed.
        $day = $day->fresh(['cycle', 'items']);

        if ($day === null || ! $this->gate->check($day->cycle, $day)->allowsOrdering()) {
            return 0;
        }

        $entries = WaitlistEntry::query()
            ->where('cycle_day_id', $day->id)
            ->when(
                $menuItemId !== null,
                // The dish that freed up, or anyone who will take anything that day.
                fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('menu_item_id', $menuItemId)
                    ->orWhereNull('menu_item_id')),
            )
            ->waiting()
            ->with('menuItem')
            ->get();

        $sent = 0;
        $remaining = $portions;

        foreach ($entries as $entry) {
            if ($remaining < 1) {
                break;
            }

            if ($this->notify($entry, $day)) {
                $sent++;
                /*
                 * Decremented by what they asked for, not by one. Somebody waiting on four
                 * portions who is told about a single freed one will find three of them
                 * gone — and texting four more people about the same portion is how a
                 * waitlist becomes a source of complaints rather than sales.
                 */
                $remaining -= max(1, $entry->quantity);
            }
        }

        return $sent;
    }

    /** @return bool whether a message actually went out */
    private function notify(WaitlistEntry $entry, CycleDay $day): bool
    {
        if (! config('sms.enabled', true)) {
            return false;
        }

        $to = GhanaPhone::normalise($entry->phone);

        if ($to === null) {
            // A number that cannot be normalised will never be textable. Mark it done so it
            // is not retried on every future cancellation for the rest of the cycle.
            $entry->forceFill(['status' => 'expired'])->save();

            return false;
        }

        $what = $entry->menuItem?->name ?? 'Food';
        $when = $day->date->format('D j M');

        SendSms::dispatch(
            $to,
            "{$what} is available again for {$when} at Mef's Kitchen. First come — order at ".
            config('app.storefront_url', config('app.url')),
            "waitlist:{$entry->id}",
        );

        $entry->markNotified();

        return true;
    }
}
