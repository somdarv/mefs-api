<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the order reaches the customer.
 *
 * `dine_in` and `takeaway` from the brief do not exist here — there is no dine-in service
 * (departure #3). `shipping` is the addition: shelf-stable pantry goods travel by courier
 * on no cooking date at all, which is why it is the one type with no cycle day.
 *
 * ⚠️ That last point is a database CHECK, not a convention —
 * `orders_fulfilment_binding_check` refuses a meal order with no day and a shipping order
 * with one. This enum decides which side of it a given order falls on.
 */
enum OrderType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    /** Pantry only. Nationwide, undated, and bound to no cycle day. */
    case Shipping = 'shipping';

    /** Meal orders are cooked on a date; shipped goods are not. */
    public function requiresCycleDay(): bool
    {
        return $this !== self::Shipping;
    }

    /** Anything that has to travel carries a fee, and it is never revenue (brief §5.3). */
    public function carriesFee(): bool
    {
        return $this !== self::Pickup;
    }

    /** Which setting holds the fee for this type. Null when there is nothing to charge. */
    public function feeSettingKey(): ?string
    {
        return match ($this) {
            self::Pickup => null,
            self::Delivery => 'delivery_fee_default',
            self::Shipping => 'pantry_shipping_fee',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::Delivery => 'Delivery',
            self::Shipping => 'Shipping',
        };
    }
}
