<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What to cook on a given day.
 *
 *     Wed 6 Aug: 14 × Waakye, 6 × Etor standard, 3 × Etor plain
 *
 * The screen she actually cooks from. It is one aggregation, and there are exactly three
 * ways to get it silently wrong — each of which produces a sheet that looks right and sends
 * the wrong amount of food to the stove:
 *
 *  1. **Counting rows instead of summing quantities.** Two lines of three portions is six
 *     portions, not two. Same arithmetic `OrderCreator::assertOrderable()` gets right, and
 *     for the same reason.
 *  2. **Collapsing to the dish.** A standard Etor and a plain Etor come out of one pot but
 *     are two different things to cook. Grouped by `menu_item_option_id` as well as
 *     `menu_item_id`, so the sheet says what to do rather than merely how much.
 *  3. **Including cancelled orders.** `Order::scopeHoldingCapacity()` is the existing
 *     definition of "still counts", and it is used here rather than a fourth hand-written
 *     `where status !=` that would one day be written differently.
 *
 * Pantry lines are excluded outright. A shipped jar of jollof base is not something to cook
 * on Wednesday, and it holds no cooking date at all.
 */
final class PrepSheet
{
    /**
     * @return array{
     *     date: string,
     *     total_portions: int,
     *     order_count: int,
     *     dishes: list<array<string, mixed>>,
     * }
     */
    public function forDate(CarbonImmutable $date): array
    {
        $lines = $this->lines($date);

        $dishes = $lines
            // The pot is the option, not the dish. See rule 2 above.
            ->groupBy(fn (OrderItem $item) => $item->menu_item_id.':'.$item->menu_item_option_id)
            ->map(fn (Collection $group) => $this->dish($group))
            ->values()
            // Biggest job first: she reads this from across a kitchen and starts at the top.
            ->sortByDesc('portions')
            ->values()
            ->all();

        return [
            'date' => $date->toDateString(),
            'total_portions' => $lines->sum('quantity'),
            'order_count' => $lines->pluck('order_id')->unique()->count(),
            'dishes' => $dishes,
        ];
    }

    /**
     * The meal lines on orders that still hold their slot for this date.
     *
     * The order ids come from `holdingCapacity()` as a subquery rather than being fetched
     * and passed back in, so the definition of "still counts" is the model's own and one
     * round trip does the whole job.
     */
    private function lines(CarbonImmutable $date): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', Order::query()
                ->holdingCapacity()
                ->whereDate('fulfil_date', $date->toDateString())
                ->select('id'))
            ->where('category', MenuCategory::Meal->value)
            ->with(['order:id,order_number,contact_name,order_type,is_paid'])
            ->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, OrderItem>  $group */
    private function dish(Collection $group): array
    {
        /** @var OrderItem $first */
        $first = $group->first();

        return [
            'menu_item_id' => $first->menu_item_id,
            'menu_item_option_id' => $first->menu_item_option_id,

            // The snapshot, never a live join — the sheet says what was sold, and a dish
            // renamed on Tuesday must not rewrite Monday's list (brief §3.2).
            'name' => $first->name,
            'size_label' => $first->size_label,
            'variant_key' => $first->variant_key,

            'portions' => (int) $group->sum('quantity'),
            'order_count' => $group->pluck('order_id')->unique()->count(),

            /*
             * ⚠️ "No pepper" is useless on a screen she cannot see it on.
             *
             * Notes travel attached to the dish they belong to, with the order number beside
             * them, because at the stove the question is "which of these fourteen is the one
             * without pepper" — and the answer is an order number she can shout.
             */
            'notes' => $group
                ->filter(fn (OrderItem $item) => filled($item->notes))
                ->map(fn (OrderItem $item) => [
                    'order_number' => $item->order?->order_number,
                    'contact_name' => $item->order?->contact_name,
                    'quantity' => $item->quantity,
                    'note' => $item->notes,
                ])
                ->values()
                ->all(),
        ];
    }
}
