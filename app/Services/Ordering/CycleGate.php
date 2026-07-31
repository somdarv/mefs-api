<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\CycleOverride;
use App\Models\CycleDay;
use App\Models\OrderCycle;
use Carbon\CarbonImmutable;

/**
 * ⚠️ THE ONLY PLACE THAT DECIDES WHETHER AN ORDER MAY BE PLACED.
 *
 * Every basket-to-order path calls this — customer checkout AND admin manual entry — with a
 * test per path. The brief is blunt about why (§5.8, trap §10.9): the original put its stock
 * gate on the direct order endpoint, which the till did not use, so the gate was live and
 * inert at the same time and a sale went through four minutes after it deployed.
 *
 * ── PRECEDENCE ────────────────────────────────────────────────────────────────
 *
 *   1. force_closed      a stop always wins; it is the safe verdict
 *   2. day closed        she said not this day, whatever the cycle says
 *   3. force_open        ← beats every clock check below. THIS IS THE REOPEN FEATURE
 *   4. not published     drafts are invisible however the dates read
 *   5. before open       the window has not started
 *   6. past cutoff       the window has ended
 *   7. capacity          cycle, then day, then dish
 *   8. open
 *
 * Order matters and is asserted directly by CycleGateTest. Reading it top to bottom is the
 * specification: an override outranks the calendar, and a closure outranks an override.
 */
final class CycleGate
{
    /**
     * May someone order for `$date`?
     *
     * `$now` is injected rather than read from the clock so tests can stand at a chosen
     * moment. Every comparison is UTC — Accra is UTC+0 year-round with no DST, so server
     * time is kitchen time (matching ../mefs/src/lib/preorder/service-days.ts).
     */
    public function check(OrderCycle $cycle, CycleDay $day, ?CarbonImmutable $now = null): OrderingState
    {
        $now ??= CarbonImmutable::now('UTC');

        // ── 1. A stop beats everything ────────────────────────────────────────
        if ($cycle->override === CycleOverride::ForceClosed) {
            return OrderingState::closed(
                'manually_closed',
                $cycle->note ?: 'Orders are closed at the moment. Check back shortly.',
            );
        }

        // ── 2. This particular day is shut ────────────────────────────────────
        // Above force_open deliberately: "open the week back up" should not silently
        // re-open the Wednesday she cancelled for a funeral.
        if (! $day->is_open) {
            return OrderingState::closed(
                'day_closed',
                'Not cooking on '.$day->date->format('l j F').'. Try another day.',
            );
        }

        // ── 3. THE REOPEN ─────────────────────────────────────────────────────
        // Before every clock check, which is precisely the point: she rings back a customer
        // at 9pm on the Monday after close and says yes.
        if ($cycle->override === CycleOverride::ForceOpen) {
            $capacity = $this->checkCapacity($cycle, $day);

            return $capacity ?? OrderingState::open(
                $this->effectiveCutoff($cycle, $day),
                'manual_reopen',
                'Orders are open.',
                $this->remaining($cycle, $day),
            );
        }

        // ── 4. Drafts are invisible ───────────────────────────────────────────
        if (! $cycle->status->isVisibleToCustomers()) {
            return OrderingState::closed('unpublished', 'Orders are not open yet.');
        }

        // ── 5. Not started ────────────────────────────────────────────────────
        if ($now->lessThan($cycle->orders_open_at)) {
            return OrderingState::notYetOpen(
                $cycle->orders_open_at,
                'Orders open '.$cycle->orders_open_at->format('j F \a\t g:ia').'.',
            );
        }

        // ── 6. Over ───────────────────────────────────────────────────────────
        $cutoff = $this->effectiveCutoff($cycle, $day);

        if ($now->greaterThanOrEqualTo($cutoff)) {
            return OrderingState::closed(
                'cutoff_passed',
                'Orders for '.$day->date->format('l j F').' closed on '
                .$cutoff->format('j F \a\t g:ia').'.',
            );
        }

        // ── 7. Full ───────────────────────────────────────────────────────────
        $capacity = $this->checkCapacity($cycle, $day);

        if ($capacity !== null) {
            return $capacity;
        }

        // ── 8. Open ───────────────────────────────────────────────────────────
        return OrderingState::open($cutoff, 'within_window', 'Orders are open.', $this->remaining($cycle, $day));
    }

    /**
     * Whether a specific dish can still be ordered on a day.
     *
     * The day may be wide open while goat jollof is gone. Checked separately so the storefront
     * can grey out one card rather than the whole day — the brief makes the same point about
     * exposing a per-option sellable map (§7.5.5).
     */
    public function checkItem(
        OrderCycle $cycle,
        CycleDay $day,
        int $menuItemId,
        int $quantity = 1,
        ?CarbonImmutable $now = null,
    ): OrderingState {
        $dayState = $this->check($cycle, $day, $now);

        if (! $dayState->allowsOrdering()) {
            return $dayState;
        }

        $entry = $day->items->firstWhere('menu_item_id', $menuItemId);

        // ⚠️ A MISSING ROW IS NOT A ZERO — but here it genuinely means "not on the menu
        // that day", which is a refusal rather than an unknown. The distinction matters:
        // this is an explicit matrix she filled in, not a balance that might be absent
        // because nobody set it up (contrast brief §7.5.2).
        if ($entry === null || ! $entry->is_available) {
            return OrderingState::closed('item_not_on_menu', 'Not on the menu that day.');
        }

        if ($entry->portion_capacity !== null) {
            $left = $entry->portion_capacity - $entry->portions_sold;

            if ($left < $quantity) {
                return $left <= 0
                    ? OrderingState::soldOut('item_capacity', 'Sold out for that day.')
                    : OrderingState::soldOut('item_capacity', "Only {$left} left for that day.");
            }
        }

        return $dayState;
    }

    /**
     * The moment ordering for this day ends.
     *
     * A per-day `cutoff_at` overrides the cycle's close — "Friday's food needs an extra
     * day's notice" is a real thing, and the alternative is splitting the week into two
     * cycles for one dish.
     */
    public function effectiveCutoff(OrderCycle $cycle, CycleDay $day): CarbonImmutable
    {
        return $day->cutoff_at ?? $cycle->orders_close_at;
    }

    /** The binding capacity limit, or null when nothing is capped. */
    private function remaining(OrderCycle $cycle, CycleDay $day): ?int
    {
        $limits = array_filter([
            $cycle->order_capacity === null ? null : $cycle->order_capacity - $cycle->orders_placed_count,
            $day->capacity === null ? null : $day->capacity - $day->orders_placed_count,
        ], fn (?int $v) => $v !== null);

        return $limits === [] ? null : (int) min($limits);
    }

    /** Non-null only when something is full. */
    private function checkCapacity(OrderCycle $cycle, CycleDay $day): ?OrderingState
    {
        if ($cycle->order_capacity !== null && $cycle->orders_placed_count >= $cycle->order_capacity) {
            return OrderingState::soldOut('cycle_capacity', 'Fully booked this week. Try the next one.');
        }

        if ($day->capacity !== null && $day->orders_placed_count >= $day->capacity) {
            return OrderingState::soldOut(
                'day_capacity',
                'Fully booked on '.$day->date->format('l j F').'. Try another day.',
            );
        }

        return null;
    }
}
