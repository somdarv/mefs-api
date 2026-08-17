<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\GhanaPhone;

/**
 * A Ghanaian mobile money network, in Paystack's spelling.
 *
 * ⚠️ THE VALUES ARE PAYSTACK'S CODES, NOT OURS. `vod` is Telecel Cash — Vodafone Ghana was
 * bought and renamed in 2023 and Paystack kept the old code. Naming the case `Telecel` while
 * leaving the value at `vod` is the whole point of a backed enum: the customer reads "Telecel
 * Cash" on screen, the gateway reads `vod`, and neither has to know about the other.
 *
 * Mirrored on the storefront by ../mefs/src/lib/validation/momo.ts — same three networks,
 * same prefixes, same labels. If one changes, both do.
 */
enum MomoNetwork: string
{
    case Mtn = 'mtn';
    case Telecel = 'vod';
    case AirtelTigo = 'atl';

    public function label(): string
    {
        return match ($this) {
            self::Mtn => 'MTN MoMo',
            self::Telecel => 'Telecel Cash',
            self::AirtelTigo => 'AirtelTigo Money',
        };
    }

    /**
     * The same three networks, in the spelling `/bank/resolve` wants.
     *
     * ⚠️ PAYSTACK USES TWO CASINGS FOR ONE THING. `POST /charge` takes `mobile_money.provider`
     * lowercase (`mtn`); `GET /bank/resolve` takes `bank_code` uppercase (`MTN`). That is
     * their inconsistency, not ours, and it lives here rather than at either call site so
     * neither has to remember it.
     */
    public function bankCode(): string
    {
        return strtoupper($this->value);
    }

    /** Every network, for a resolve that has to try them all because the prefix lied. */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The network a number was ISSUED on.
     *
     * ⚠️ A GUESS, AND IT MUST STAY OVERRIDABLE. Ghana has had number portability since 2011,
     * so an 024 number can sit on any network — the prefix says who issued it, not who
     * carries it today. This exists to pre-fill a field, never to decide one: guess wrong and
     * the prompt goes to a wallet that does not exist, and the customer is told their own
     * number is wrong. The checkout screen shows what was inferred and lets them change it.
     *
     * Returns null for a prefix we do not recognise, which the caller must handle rather than
     * falling back to MTN because it is the biggest. A silent default here is how someone ends
     * up staring at a phone that will never ring.
     */
    public static function forPhone(string $phone): ?self
    {
        $e164 = GhanaPhone::normalise($phone);

        if ($e164 === null) {
            return null;
        }

        // The two digits after the country code: `+233` `24` `1234567`.
        return match (substr($e164, 4, 2)) {
            '24', '54', '55', '59', '25', '53' => self::Mtn,
            '20', '50' => self::Telecel,
            '27', '57', '26', '56' => self::AirtelTigo,
            default => null,
        };
    }
}
