<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\MomoNetwork;
use App\Support\GhanaPhone;

/**
 * Which wallet to debit, and on which network.
 *
 * ⚠️ THIS IS NOT `orders.contact_phone`, AND THE TWO MUST NOT BECOME ONE FIELD. The contact
 * number is who she rings when the food is ready; this is whose money moves. In Ghana they
 * diverge constantly — a partner pays, the work SIM is not the MoMo SIM, someone orders for
 * their mother. Collapse them and a customer who retries on a different wallet has silently
 * changed who gets the collection text: a quiet failure of exactly the kind this codebase
 * spends its comments on.
 *
 * It is carried on the ATTEMPT rather than the order, for the same reason the attempt row
 * exists at all — the first try goes to the wrong wallet and the second goes somewhere else,
 * and both are facts worth keeping.
 */
final readonly class MomoInstruction
{
    private function __construct(
        /** E.164. Paystack is handed this, never what the customer typed. */
        public string $phone,
        public MomoNetwork $network,
    ) {}

    /**
     * Build one from what the customer sent, or null when there is nothing chargeable here.
     *
     * ⚠️ AN EXPLICIT NETWORK BEATS THE INFERRED ONE. Portability means the customer knows and
     * we are guessing (see `MomoNetwork::forPhone()`), so a value that came from the request
     * is never second-guessed by a prefix table.
     *
     * Null is returned rather than thrown for an unreadable number or an unknown network:
     * `PaymentInitiator` turns it into a refusal with a reason, and the ORDER still stands.
     */
    public static function from(?string $phone, ?string $network = null): ?self
    {
        if ($phone === null) {
            return null;
        }

        $e164 = GhanaPhone::normalise($phone);

        if ($e164 === null) {
            return null;
        }

        $resolved = $network !== null ? MomoNetwork::tryFrom($network) : null;
        $resolved ??= MomoNetwork::forPhone($e164);

        return $resolved === null ? null : new self($e164, $resolved);
    }
}
