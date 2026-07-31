<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one payment ATTEMPT.
 *
 * A `payments` row per attempt, not per order — a customer who abandons Paystack and comes
 * back leaves two rows, and only one of them completed. `orders.payment_status` is the
 * order's answer; this is a single attempt's.
 *
 * ⚠️ `abandoned` exists on `payments` and NOT on `orders.payment_status` — the constraints
 * differ deliberately. An abandoned attempt leaves the order `pending`, because she can
 * still ring the customer; an order is never "abandoned".
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Initialised, never returned from. Attempts only. */
    case Abandoned = 'abandoned';

    case Refunded = 'refunded';

    /** Statuses `orders.payment_status` accepts. `abandoned` is absent on purpose. */
    public function isValidForOrder(): bool
    {
        return $this !== self::Abandoned;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Paid',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Refunded => 'Refunded',
        };
    }
}
