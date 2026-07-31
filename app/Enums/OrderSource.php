<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the order came from. Three, not the brief's five — there is no POS and no social
 * checkout in v1 (departure #2).
 *
 * `phone` and `whatsapp` both arrive as admin manual entry, and both set
 * `is_manual_entry`. The distinction between them is hers, for the insights screen: "how
 * much of this week came through WhatsApp?" is a question she will ask.
 */
enum OrderSource: string
{
    case Online = 'online';
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';

    /**
     * Whether an order from this source may hold a slot unpaid (departure #6).
     *
     * Her regulars ring up and pay by MoMo transfer after the call, so an order she takes
     * by hand holds its slot until `slot_hold_expires_at`. A customer-placed order may not:
     * an unpaid online order that held capacity would let anyone book out the week for
     * free.
     */
    public function mayHoldUnpaid(): bool
    {
        return $this !== self::Online;
    }

    public function isManualEntry(): bool
    {
        return $this !== self::Online;
    }

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Phone => 'Phone',
            self::Whatsapp => 'WhatsApp',
        };
    }
}
