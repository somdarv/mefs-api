<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\MenuCategory;
use App\Models\MenuOption;

/**
 * A basket line once the server has looked it up: the option, the dish it belongs to, and
 * the price *the server* says it costs.
 *
 * This is also the snapshot that lands on `order_items`. Name, price, size and category are
 * copied at order time and never joined live, so a price rise next Tuesday cannot rewrite
 * what last Tuesday's customer agreed to pay (brief §3.2).
 */
final readonly class PricedLine
{
    public function __construct(
        public MenuOption $option,
        public int $menuItemId,
        public string $name,
        public int $unitPrice,
        public int $quantity,
        public MenuCategory $category,
        public ?string $sizeLabel = null,
        public ?string $variantKey = null,
        public ?string $notes = null,
    ) {}

    /** Meal lines are cooked on a date and consume that day's portions. Pantry lines do not. */
    public function isDateBound(): bool
    {
        return $this->category->isDateBound();
    }

    /** @return array<string, mixed> */
    public function toOrderItemAttributes(): array
    {
        return [
            'menu_item_id' => $this->menuItemId,
            'menu_item_option_id' => $this->option->id,
            'name' => $this->name,
            'unit_price' => $this->unitPrice,
            'size_label' => $this->sizeLabel,
            'variant_key' => $this->variantKey,
            'category' => $this->category->value,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
