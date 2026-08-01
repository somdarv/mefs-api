<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Enums\OrderStatus;
use App\Models\CycleDayItem;
use App\Models\Order;
use App\Models\OrderCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "What did I actually make last week?"
 *
 * ⚠️ EVERY REVENUE FIGURE HERE EXCLUDES PASS-THROUGH DELIVERY FEES (brief §5.3).
 *
 * She uses a third-party courier: the fee is collected and handed straight over. Counting it
 * as income overstates every number on the screen, and the overstatement grows with delivery
 * volume — so the busier she gets, the more wrong the dashboard becomes, which is the worst
 * possible direction for a number to be wrong in.
 *
 * The subtraction is `Order::revenueTotal()` and it is called **per order**, in PHP, rather
 * than reimplemented as a SQL `CASE`. That is a deliberate trade of a little speed for
 * having exactly one definition of revenue in the codebase: a second one in a query would
 * agree with the first right up until somebody changed one of them. Volume here is a
 * kitchen's, not a marketplace's.
 *
 * The four questions this answers are hers, not a dashboard's:
 *
 *   - what came in, and how much of it is actually mine
 *   - which dishes sell, by portions and by money
 *   - how much of the week is WhatsApp rather than online
 *   - how often a day sells out — a pricing and capacity signal, not a vanity metric
 */
final class Insights
{
    /** @return array<string, mixed> */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = $this->ordersIn($from, $to);

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'revenue' => $this->revenue($orders),
            'channels' => $this->channels($orders),
            'dishes' => $this->dishes($orders->pluck('id')->all()),
            'cycles' => $this->cycles($orders),
            'sell_outs' => $this->sellOuts($from, $to),
        ];
    }

    /**
     * Orders in the window, cancelled ones excluded.
     *
     * Keyed on `fulfil_date` rather than `placed_at`: she thinks in cooking weeks, and an
     * order taken on the 1st for the 8th belongs to the week she cooks it. A pantry-only
     * order has no `fulfil_date` at all, so it falls back to when it was placed — shipped
     * goods still happened, and dropping them would make the pantry line invisible in every
     * figure on the page.
     *
     * @return Collection<int, Order>
     */
    private function ordersIn(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Order::query()
            ->holdingCapacity()
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('fulfil_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhere(function ($sub) use ($from, $to): void {
                        $sub->whereNull('fulfil_date')
                            ->whereBetween('placed_at', [$from->startOfDay(), $to->endOfDay()]);
                    });
            })
            ->get();
    }

    /** @param  Collection<int, Order>  $orders */
    private function revenue(Collection $orders): array
    {
        $paid = $orders->filter(fn (Order $order) => $order->is_paid);

        return [
            // The number. Gross minus every courier's share.
            'total' => $orders->sum(fn (Order $order) => $order->revenueTotal()),
            'paid' => $paid->sum(fn (Order $order) => $order->revenueTotal()),
            'unpaid' => $orders->reject(fn (Order $order) => $order->is_paid)
                ->sum(fn (Order $order) => $order->revenueTotal()),

            /*
             * Shown, never added. This is what the couriers took, and it is on the screen
             * precisely so that "why is my revenue lower than my totals" has an answer
             * visible rather than one she has to be told about once and remember.
             */
            'pass_through_fees' => $orders
                ->filter(fn (Order $order) => $order->delivery_fee_collection === 'third_party')
                ->sum('delivery_fee'),

            'gross' => $orders->sum('total'),

            'order_count' => $orders->count(),
            'paid_count' => $paid->count(),

            // Integer division, deliberately: the average of a set of pesewa amounts is a
            // pesewa amount. A fractional pesewa is not money.
            'average_order' => $orders->count() === 0
                ? 0
                : intdiv((int) $orders->sum(fn (Order $order) => $order->revenueTotal()), $orders->count()),
        ];
    }

    /**
     * Where the week's work came from.
     *
     * The point of this one is the split, not the total: if two thirds of a week arrives on
     * WhatsApp, the storefront is not where the business is, and that changes what is worth
     * building next.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function channels(Collection $orders): array
    {
        return $orders
            ->groupBy(fn (Order $order) => $order->source->value)
            ->map(fn (Collection $group, string $source) => [
                'source' => $source,
                'order_count' => $group->count(),
                'revenue' => $group->sum(fn (Order $order) => $order->revenueTotal()),
            ])
            ->values()
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Which dishes actually sell.
     *
     * ⚠️ BY OPTION, like the prep sheet — a standard Etor and a plain Etor sell at very
     * different prices, and collapsing them to the dish hides the one fact this table exists
     * to surface.
     *
     * SQL here rather than PHP, because a line total is `unit_price × quantity` and carries
     * none of the revenue ambiguity above. There is nothing to get subtly wrong.
     *
     * @param  list<int>  $orderIds
     */
    private function dishes(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->groupBy('menu_item_id', 'menu_item_option_id', 'name', 'size_label', 'category')
            ->select([
                'menu_item_id',
                'menu_item_option_id',
                'name',
                'size_label',
                'category',
                DB::raw('SUM(quantity)::int as portions'),
                DB::raw('SUM(unit_price * quantity)::int as revenue'),
            ])
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Revenue per cooking week — the unit she actually plans in.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function cycles(Collection $orders): array
    {
        $names = OrderCycle::query()
            ->whereIn('id', $orders->pluck('order_cycle_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $orders
            ->whereNotNull('order_cycle_id')
            ->groupBy('order_cycle_id')
            ->map(fn (Collection $group, $cycleId) => [
                'order_cycle_id' => (int) $cycleId,
                'name' => $names[(int) $cycleId] ?? 'Unknown cycle',
                'order_count' => $group->count(),
                'revenue' => $group->sum(fn (Order $order) => $order->revenueTotal()),
            ])
            ->values()
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * How often a capped dish ran out.
     *
     * ⚠️ ONLY CAPPED ROWS COUNT. A dish with `portion_capacity: null` is uncapped, so it can
     * never sell out — including it in the denominator would report a kitchen that never
     * caps anything as "0% sold out" and make the number look reassuring when it is simply
     * not being measured.
     *
     * Selling out is not a success metric. It means she left money on the table or the cap
     * was set too low, and it is here to be acted on rather than celebrated.
     */
    private function sellOuts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $capped = CycleDayItem::query()
            ->join('cycle_days', 'cycle_days.id', '=', 'cycle_day_items.cycle_day_id')
            ->whereBetween('cycle_days.date', [$from->toDateString(), $to->toDateString()])
            ->where('cycle_day_items.is_available', true)
            ->whereNotNull('cycle_day_items.portion_capacity')
            ->select([
                'cycle_days.date',
                'cycle_day_items.menu_item_id',
                'cycle_day_items.portion_capacity',
                'cycle_day_items.portions_sold',
            ])
            ->get();

        $soldOut = $capped->filter(
            fn ($row) => (int) $row->portions_sold >= (int) $row->portion_capacity,
        );

        return [
            'capped_dish_days' => $capped->count(),
            'sold_out_dish_days' => $soldOut->count(),
            'dates' => $soldOut->pluck('date')->unique()->sort()->values()->all(),
        ];
    }

    /** Statuses excluded from every figure above, stated once so a caller can show it. */
    public static function excludedStatus(): string
    {
        return OrderStatus::Cancelled->value;
    }
}
