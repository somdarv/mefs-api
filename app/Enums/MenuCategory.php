<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two halves of what she sells, and they behave differently enough that this is a real
 * distinction rather than a label.
 */
enum MenuCategory: string
{
    /** Cooked to order for a specific date. Tied to a cycle day, collected or delivered. */
    case Meal = 'meal';

    /**
     * Shelf-stable — jollof base, shito, spice rubs. Ships nationwide, on no cooking date
     * at all. A pantry-only order therefore has no `fulfil_date`, which is why the cart
     * holds fulfilment groups rather than one flat list.
     */
    case Pantry = 'pantry';

    public function isDateBound(): bool
    {
        return $this === self::Meal;
    }
}
