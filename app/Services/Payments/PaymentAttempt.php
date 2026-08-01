<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * The outcome of asking Paystack to start a payment.
 *
 * ⚠️ THREE STATES, AND `unavailable` MUST NOT BLOCK A SALE (brief Law 7).
 *
 *   started      there is a hosted checkout page to send the customer to
 *   refused      Paystack said no — a bad amount, a disabled account
 *   unavailable  we could not tell: no keys configured, gateway down, timeout
 *
 * The `unavailable` case is the one that matters most here, because it is the state the
 * system is in **right now**: no keys have been supplied. The order is still created, still
 * holds its slot for the payment window, and still appears in her back office — it simply
 * has no checkout link, and she arranges payment the way she does today.
 *
 * A boolean would have collapsed "not configured yet" into "payment failed", and the
 * difference between those two is the difference between a working shop and a broken one.
 */
final readonly class PaymentAttempt
{
    private function __construct(
        public string $status,
        public string $reason,
        public ?Payment $payment = null,
        /** Paystack's hosted checkout page. Null unless `started`. */
        public ?string $authorizationUrl = null,
    ) {}

    public static function started(Payment $payment, string $authorizationUrl): self
    {
        return new self('started', 'initialised', $payment, $authorizationUrl);
    }

    public static function refused(string $reason, ?Payment $payment = null): self
    {
        return new self('refused', $reason, $payment);
    }

    public static function unavailable(string $reason, ?Payment $payment = null): self
    {
        return new self('unavailable', $reason, $payment);
    }

    public function wasStarted(): bool
    {
        return $this->status === 'started';
    }

    /**
     * What the confirm endpoint puts on the order payload.
     *
     * Null when there is nothing to send the customer to, so the storefront's check is
     * `payment === null` rather than an inspection of a status string it would have to keep
     * in sync with this file.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->wasStarted()) {
            return null;
        }

        return [
            'reference' => $this->payment?->reference,
            'authorization_url' => $this->authorizationUrl,
            'amount' => $this->payment?->amount,
        ];
    }
}
