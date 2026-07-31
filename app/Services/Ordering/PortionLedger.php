<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\MenuCategory;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * `cycle_day_items.portions_sold`, and the only thing that moves it.
 *
 * ⚠️ THIS IS THE NUMBER `CycleGate::checkItem()` SUBTRACTS FROM. If it drifts, the gate
 * keeps refusing orders for a dish that is actually available, or — worse — keeps taking
 * them for one that is not. Both directions are silent.
 *
 * So reservation and release are one service with two methods rather than an increment in
 * the creator and a decrement somewhere in the status machine. There is exactly one
 * definition of what a portion is and when it comes back.
 *
 * Pantry lines never touch this. Shelf-stable goods belong to no cooking day, have no row
 * in the matrix, and are capped by stock (v2 IMS) rather than by the day's pot.
 */
final class PortionLedger
{
    /**
     * Take portions off the day, atomically.
     *
     * `increment` on the query builder, not read-then-write on the model: two customers
     * confirming at the same instant must not both read 6 and both write 7. The gate check
     * that precedes this runs under a row lock on the day (see `OrderCreator`), which is
     * what makes the *decision* safe; this makes the *arithmetic* safe.
     *
     * @param  list<PricedLine>  $lines
     */
    public function reserve(CycleDay $day, array $lines): void
    {
        foreach ($this->mealQuantities($lines) as $menuItemId => $quantity) {
            CycleDayItem::query()
                ->where('cycle_day_id', $day->id)
                ->where('menu_item_id', $menuItemId)
                ->increment('portions_sold', $quantity);
        }
    }

    /**
     * Give them back when an order is cancelled.
     *
     * Clamped at zero by the `GREATEST`: `portions_sold` is unsigned, and a double release
     * — a cancellation replayed, a hold expiring on an already-cancelled order — would
     * otherwise fail on the column constraint and take the cancellation down with it.
     * Refusing to go below zero is the conservative direction: it can only ever make the
     * kitchen look busier than it is, never emptier.
     */
    public function release(Order $order): void
    {
        if ($order->cycle_day_id === null) {
            return;
        }

        $quantities = $order->items
            ->where('category', MenuCategory::Meal->value)
            ->groupBy('menu_item_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        foreach ($quantities as $menuItemId => $quantity) {
            CycleDayItem::query()
                ->where('cycle_day_id', $order->cycle_day_id)
                ->where('menu_item_id', $menuItemId)
                ->update([
                    'portions_sold' => DB::raw("GREATEST(portions_sold - {$quantity}, 0)"),
                ]);
        }
    }

    /**
     * Meal quantities summed per dish.
     *
     * Summed, because a basket can hold two options of the same dish — a standard Etor and
     * a plain one are two lines and three portions out of the same pot. Counting lines
     * instead of portions is how a day sells out one order late.
     *
     * @param  list<PricedLine>  $lines
     * @return array<int, int>
     */
    public function mealQuantities(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            if (! $line->isDateBound()) {
                continue;
            }

            $totals[$line->menuItemId] = ($totals[$line->menuItemId] ?? 0) + $line->quantity;
        }

        return $totals;
    }
}
