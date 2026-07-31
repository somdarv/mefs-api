<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use InvalidArgumentException;

/**
 * One line of a basket, as the client is allowed to express it.
 *
 * ⚠️ NOTE WHAT IS NOT HERE: A PRICE.
 *
 * A line is a reference — which sellable option, how many — and nothing else. The server
 * looks the price up. A client that could send `unit_price` could send `1`, and no amount
 * of validation downstream fixes an architecture that accepts a number the customer chose
 * (brief Law: client totals are display only).
 *
 * It keys to `menu_item_option_id`, not to the dish: the option is the sellable unit, and
 * v2 recipes key off it (brief §3.2, §12.2).
 */
final readonly class BasketLine
{
    public function __construct(
        public int $menuItemOptionId,
        public int $quantity,
        public ?string $notes = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A basket line needs at least one of something.');
        }
    }

    /** @param array{menu_item_option_id: int|string, quantity: int|string, notes?: string|null} $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) $row['menu_item_option_id'],
            (int) $row['quantity'],
            $row['notes'] ?? null,
        );
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<self>
     */
    public static function listFrom(iterable $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = self::fromArray($row);
        }

        return $lines;
    }

    /** @return array{menu_item_option_id: int, quantity: int, notes: string|null} */
    public function toArray(): array
    {
        return [
            'menu_item_option_id' => $this->menuItemOptionId,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
