<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which basket lines a discount is calculated against.
 *
 * ⚠️ THE SCOPE NARROWS THE **DISCOUNTABLE SUBTOTAL**, NOT WHETHER THE CODE APPLIES.
 *
 * "20% off the pantry range" on a basket holding one jar and four dinners must take 20% of
 * the jar, not 20% of the basket that happens to contain one. Treating scope as a yes/no
 * gate on the whole order is how a launch code for a ₵45 product ends up taking ₵90 off a
 * catering order.
 */
enum PromoScope: string
{
    case All = 'all';

    /** Cooked-to-order dishes — anything bound to a cooking day. */
    case Meals = 'meals';

    /** The shelf-stable line. Sold nationwide, not tied to a date. */
    case Pantry = 'pantry';

    /** Does a line of this category count towards the discountable subtotal? */
    public function covers(MenuCategory $category): bool
    {
        return match ($this) {
            self::All => true,
            self::Meals => $category->isDateBound(),
            self::Pantry => ! $category->isDateBound(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everything',
            self::Meals => 'Meals only',
            self::Pantry => 'Pantry only',
        };
    }
}
