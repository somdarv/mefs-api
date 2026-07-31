<?php

declare(strict_types=1);

namespace App\Enums;

/** A basket's life: being filled, turned into an order, or swept. */
enum CheckoutSessionStatus: string
{
    case Open = 'open';

    /** It became an order. A confirmed session is read-only and cannot confirm twice. */
    case Confirmed = 'confirmed';

    case Expired = 'expired';

    public function isEditable(): bool
    {
        return $this === self::Open;
    }
}
