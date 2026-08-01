<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderingStatus;
use App\Http\Responses\ApiResponse;
use App\Models\CycleDay;
use App\Models\MenuItem;
use App\Models\WaitlistEntry;
use App\Rules\PhoneNumber;
use App\Services\Ordering\CycleGate;
use App\Support\GhanaPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Text me if a portion comes back."
 *
 * ⚠️ THE ONLY PLACE THIS SYSTEM CAPTURES DEMAND IT COULD NOT MEET. Every other table records
 * what sold; a sold-out day otherwise leaves no trace of the eleven people who wanted it —
 * which is exactly the number that should be setting next week's capacity.
 */
final class WaitlistController extends Controller
{
    public function __construct(private readonly CycleGate $gate) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cycle_day_id' => ['required', 'integer', 'exists:cycle_days,id'],
            // Null means "anything that day", which is a real request and not an omission.
            'menu_item_id' => ['present', 'nullable', 'integer', 'exists:menu_items,id'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new PhoneNumber],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $day = CycleDay::query()->with(['cycle', 'items'])->findOrFail($data['cycle_day_id']);

        /*
         * ⚠️ REFUSED WHEN THE DAY IS STILL ORDERABLE, AND THE REFUSAL IS THE FEATURE.
         *
         * A waitlist entry for food somebody could simply buy is a promise to text them
         * about something already available — and worse, it is a customer who thinks they
         * have done something and waits. The message names the state, so the storefront can
         * send them to checkout instead.
         *
         * ⚠️ MATCHED ON `status`, NOT ON `reason`. `sold_out` is the status; the reasons
         * underneath it are `item_capacity` and `cycle_capacity`, and a string comparison
         * against "sold_out" there would silently never match — the exact shape of a check
         * that looks enforced and is not.
         *
         * `sold_out` is the only state this endpoint is for. Past the cutoff, day closed and
         * force-closed are not things a freed portion fixes, so joining a list for them
         * would be a promise nothing can keep.
         */
        /*
         * ⚠️ THE ITEM GATE WHEN A DISH IS NAMED, THE DAY GATE WHEN ONE IS NOT.
         *
         * `check()` answers "is this day orderable" and knows nothing about a single dish
         * running out — that is `checkItem()`. Asking only the day gate would report a day
         * with one sold-out dish as perfectly open, and this endpoint would refuse every
         * request it exists to serve, with the reasonable-sounding message "that day is
         * still open". Which it is. Just not for waakye.
         */
        $state = $data['menu_item_id'] === null
            ? $this->gate->check($day->cycle, $day)
            : $this->gate->checkItem($day->cycle, $day, $data['menu_item_id'], 1);

        if ($state->allowsOrdering()) {
            return ApiResponse::error('That day is still open — you can order now.', 422, [
                'cycle_day_id' => ['That day is still open — you can order now.'],
            ]);
        }

        if ($state->status !== OrderingStatus::SoldOut) {
            return ApiResponse::error($state->message, 422, [
                'cycle_day_id' => [$state->message],
                'refusal' => $state->toArray(),
            ]);
        }

        $phone = GhanaPhone::normalise($data['phone']);
        abort_if($phone === null, 422, 'Enter a Ghanaian mobile number, like 024 123 4567.');

        /*
         * ⚠️ `updateOrCreate` AGAINST THE UNIQUE INDEX, NOT `create`.
         *
         * Somebody tapping the button three times must get one entry — and a check-then-
         * insert would race with itself on a double tap. The index is `NULLS NOT DISTINCT`
         * so the "anything that day" case dedupes like every other one.
         *
         * ⚠️ AND `status` IS NOT IN THE UPDATE. Re-joining must not resurrect an entry that
         * has already been notified, or a cancellation would text the same person twice for
         * the same portion.
         */
        $entry = WaitlistEntry::query()->updateOrCreate(
            [
                'cycle_day_id' => $day->id,
                'menu_item_id' => $data['menu_item_id'] ?? null,
                'phone' => $phone,
            ],
            [
                'name' => $data['name'],
                'quantity' => $data['quantity'] ?? 1,
                'customer_id' => $request->user()?->customer?->id,
            ],
        );

        $dish = $data['menu_item_id'] === null
            ? null
            : MenuItem::query()->find($data['menu_item_id'])?->name;

        return ApiResponse::created([
            'id' => $entry->id,
            'status' => $entry->status,
        ], sprintf(
            'You\'re on the list for %s on %s. We\'ll text you if a portion comes back.',
            $dish ?? 'that day',
            $day->date->format('D j M'),
        ));
    }
}
