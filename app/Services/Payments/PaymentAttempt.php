<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * The outcome of asking Paystack to start a payment.
 *
 * ⚠️ THREE STATES, AND `unavailable` MUST NOT BLOCK A SALE (brief Law 7).
 *
 *   prompted     the handset is buzzing; nothing has been paid yet
 *   refused      Paystack said no — a bad number, a bad amount, a disabled account
 *   unavailable  we could not tell: no keys configured, gateway down, timeout
 *
 * ⚠️ `prompted` IS NOT `paid`, AND THE NAME IS DOING WORK. The old `started` state handed
 * back a checkout URL, and "started" was a fair description of a customer who had arrived at
 * a payment page. A pushed prompt is different: the request succeeded, the money has not
 * moved, and it may never move — the customer can ignore the prompt until it expires. Every
 * screen downstream reads this as "waiting", never as "done"; the webhook is what settles it.
 *
 * The `unavailable` case is the one that matters most here, because it is the state the
 * system is in **right now**: no keys have been supplied. The order is still created, still
 * holds its slot for the payment window, and still appears in her back office — no prompt is
 * sent, and she arranges payment the way she does today.
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
        /** Paystack's own sentence for the customer. Null unless `prompted`. */
        public ?string $displayText = null,
    ) {}

    public static function prompted(Payment $payment, ?string $displayText = null): self
    {
        return new self('prompted', 'prompt_sent', $payment, $displayText);
    }

    /**
     * Paystack wants a one-time code before it will move the money.
     *
     * ⚠️ A FOURTH STATE, AND IT IS NOT `prompted`. On some Ghana networks and accounts the
     * charge answers `send_otp`: Paystack texts the customer a code and waits for
     * `/charge/submit_otp`. Nothing buzzes, nothing is pending on the handset, and there is
     * nothing for the customer to approve. Folding it into `prompted` — which is what used to
     * happen, because only `failed` was ever inspected — told people to approve a prompt that
     * did not exist while the code they were actually holding went unasked-for, and the charge
     * sat unfinished until it expired.
     */
    public static function otpRequired(Payment $payment, ?string $displayText = null): self
    {
        return new self('otp_required', 'otp_required', $payment, $displayText);
    }

    public function needsOtp(): bool
    {
        return $this->status === 'otp_required';
    }

    /** Either way the charge is live and the customer is being asked for something. */
    public function isUnderway(): bool
    {
        return $this->wasPrompted() || $this->needsOtp();
    }

    public static function refused(string $reason, ?Payment $payment = null): self
    {
        return new self('refused', $reason, $payment);
    }

    public static function unavailable(string $reason, ?Payment $payment = null): self
    {
        return new self('unavailable', $reason, $payment);
    }

    public function wasPrompted(): bool
    {
        return $this->status === 'prompted';
    }

    /**
     * What the confirm endpoint puts on the order payload.
     *
     * Null when no prompt went out, so the storefront's check is `payment === null` rather
     * than an inspection of a status string it would have to keep in sync with this file.
     *
     * ⚠️ `display_text` IS PAYSTACK'S SENTENCE AND MAY BE ABSENT. The screen carries its own
     * copy and uses this only when it is there — a waiting state whose only instruction comes
     * from a third party's optional field is a blank screen on the day they drop it.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->isUnderway() || $this->payment === null) {
            return null;
        }

        return [
            // ⚠️ THE CLIENT BRANCHES ON THIS, not on the presence of the object. `prompted`
            // means "watch the handset"; `otp_required` means "we need the code Paystack just
            // texted you". Two completely different screens, and the difference is invisible
            // in every other field here.
            'state' => $this->status,
            'reference' => $this->payment->reference,
            'amount' => $this->payment->amount,
            'momo_phone' => $this->payment->momo_phone,
            'momo_network' => $this->payment->momo_network?->value,
            'network_label' => $this->payment->momo_network?->label(),
            'display_text' => $this->displayText,
            'expires_at' => $this->payment->prompt_expires_at?->toIso8601String(),
            // The rehearsal panel turns on this, not on a query parameter. A simulated
            // attempt must never be indistinguishable from a real one on screen.
            'is_simulated' => $this->payment->is_simulated,
        ];
    }
}
