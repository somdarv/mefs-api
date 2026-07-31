<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Models\MenuOption;

/**
 * Basket references in, priced snapshots out.
 *
 * ⚠️ ONE LOOKUP, USED BY BOTH THE QUOTE AND THE ORDER.
 *
 * The checkout screen has to show a total before the customer commits, and the order has to
 * be priced when they do. If those are two implementations they drift, and the day they
 * drift the customer is looking at ₵58.00 while their bank says ₵60.00 — and they are
 * right. Same service, same prices, same snapshot fields.
 *
 * The price comes from the catalogue row. Never from the request: a client that could send
 * `unit_price` could send `1`.
 */
final class BasketPricer
{
    /**
     * @param  list<BasketLine>  $lines
     * @return list<PricedLine>
     *
     * @throws OrderRefused
     */
    public function price(array $lines): array
    {
        $ids = array_values(array_unique(array_map(fn (BasketLine $l) => $l->menuItemOptionId, $lines)));

        // One query for every option, then a lookup. Not a query per line.
        $options = MenuOption::query()
            ->with('menuItem')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $priced = [];

        foreach ($lines as $line) {
            /** @var MenuOption|null $option */
            $option = $options->get($line->menuItemOptionId);

            // A soft-deleted option, a deactivated one, or an id that never existed. All
            // three are "no longer on sale" to the customer, and none of them may be
            // guessed at — an order line pointing at nothing is a receipt nobody can honour.
            if ($option === null || $option->menuItem === null || ! $option->menuItem->is_active) {
                throw OrderRefused::unknownOption($line->menuItemOptionId);
            }

            $item = $option->menuItem;

            $priced[] = new PricedLine(
                option: $option,
                menuItemId: $item->id,
                // The name as the customer saw it: "Waakye — Standard". Snapshot, not a join.
                name: $item->name.($option->label === '' ? '' : ' — '.$option->label),
                unitPrice: $option->price,
                quantity: $line->quantity,
                category: $item->category,
                sizeLabel: $option->size_label,
                variantKey: $option->variant_key,
                notes: $line->notes,
            );
        }

        return $priced;
    }

    /** True when nothing in the basket is cooked on a date — i.e. it can be shipped. */
    public function isPantryOnly(array $priced): bool
    {
        foreach ($priced as $line) {
            if ($line->isDateBound()) {
                return false;
            }
        }

        return $priced !== [];
    }
}
