<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a discount is worked out.
 *
 * ⚠️ WIDENING THIS MEANS UPDATING `promos_value_check` TOO (trap §10.11). The constraint
 * spells out both cases by name, so a third one added here without touching the migration
 * fails at insert with a message about a check constraint rather than about the feature.
 */
enum PromoType: string
{
    /** `value` is a percentage, 1–100, applied to the discountable subtotal. */
    case Percentage = 'percentage';

    /** `value` is a flat amount in pesewas. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage off',
            self::Fixed => 'Fixed amount off',
        };
    }
}
