<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Start a payment for an order: push a mobile money prompt to the customer's handset.
 *
 * ⚠️ THE CUSTOMER NEVER LEAVES THE SHOP. There is no hosted checkout and no redirect — they
 * tap pay, their phone buzzes, they approve it there. `PaystackClient` has no `initialize()`
 * at all, so there is no second path that could quietly come back.
 *
 * ⚠️ A PROMPT IS NOT A PAYMENT. `charge` coming back `ok` means Paystack accepted the
 * instruction and the handset is about to ring; the money moves — or does not — minutes
 * later, off our wire entirely. Only `PaymentRecorder`, driven by the webhook or by a
 * server-to-server verify, ever marks anything paid.
 *
 * ⚠️ ONE `payments` ROW PER ATTEMPT, NOT PER ORDER. A customer whose first prompt went to the
 * wrong wallet tries again on another number, and both attempts are facts: the second row is
 * what makes "we tried twice, on two different numbers" answerable at month end. Modelling it
 * as one row per order means the second overwrites the first, and the reconciliation screen
 * in M6 then cannot explain a settlement mentioning a reference the system has forgotten.
 *
 * ⚠️ AND IT NEVER THROWS. No keys, no number, gateway down, timeout — all of them come back
 * as `unavailable` or `refused`, the ORDER stands, and she arranges payment the way she does
 * today. Law 7: a check that cannot be evaluated must not block a sale. That is not a
 * hypothetical here; it is the state the system is in until keys are supplied.
 */
final class PaymentInitiator
{
    public function __construct(private readonly PaystackClient $paystack) {}

    public function begin(Order $order, ?MomoInstruction $momo = null): PaymentAttempt
    {
        if ($order->is_paid) {
            return PaymentAttempt::refused('already_paid');
        }

        if (SystemSetting::get('payment_mode', 'live') === 'simulate') {
            return $this->beginSimulated($order, $momo);
        }

        /*
         * ⚠️ NO NUMBER MEANS NO PROMPT, AND THAT IS A REFUSAL RATHER THAN A CRASH.
         *
         * There is nowhere to send a prompt to, and there is no fallback screen to fall back
         * to any more. Checkout always collects one — pre-filled from the contact number — so
         * in practice this fires for an admin-entered order being paid later, where nobody has
         * said which wallet to debit yet. The order is untouched; `payment` comes back null
         * and the screen asks for a number.
         *
         * Deliberately NOT defaulted to `$order->contact_phone`. See `MomoInstruction`: the
         * number she rings and the wallet that pays are different facts, and guessing that
         * they are the same is how a prompt goes to someone who is not paying.
         */
        if ($momo === null) {
            return PaymentAttempt::refused('momo_number_missing');
        }

        if (! $this->paystack->isConfigured()) {
            // The current state of the world. Not an error, not logged as one — the shop
            // works, orders arrive unpaid, and the checkout screen says she will call.
            return PaymentAttempt::unavailable('not_configured');
        }

        $payment = $this->attemptRow($order, $momo, provider: 'paystack', simulated: false);

        $result = $this->paystack->charge(
            reference: $payment->reference,
            amountMinor: $payment->amount,
            // Paystack requires an email and most of her customers do not have one on file.
            // A per-order address on our own domain is deliverable-shaped, unique, and never
            // pretends to be the customer's — inventing `ama@gmail.com` would be worse.
            email: $this->email($order),
            currency: $payment->currency,
            phone: $momo->phone,
            provider: $momo->network->value,
            metadata: [
                'order_number' => $order->order_number,
                'order_id' => $order->id,
                // The number to RING, kept alongside the number being CHARGED precisely
                // because they are allowed to differ.
                'contact_phone' => $order->contact_phone,
            ],
        );

        if (! ($result['ok'] ?? false)) {
            // The attempt row stays, marked. A payment we could not start is a fact worth
            // keeping — deleting it would make "we tried three times" unknowable.
            $reason = (string) ($result['reason'] ?? 'unknown');
            $retryable = in_array($reason, ['transport_error', 'gateway_error', 'not_configured'], true);

            $payment->update([
                'status' => $retryable ? PaymentStatus::Pending->value : PaymentStatus::Failed->value,
                // The clock comes off a prompt that was never sent, so nothing downstream
                // shows "check your phone" for a charge Paystack refused.
                'prompt_expires_at' => null,
                'payload' => ['error' => $reason],
            ]);

            return $retryable
                ? PaymentAttempt::unavailable($reason, $payment)
                : PaymentAttempt::refused($reason, $payment);
        }

        $data = $result['data'] ?? [];
        $payment->update(['payload' => $data]);

        /*
         * ⚠️ EVERY NON-FAILED STATUS IS TREATED AS "WAITING", INCLUDING `success`.
         *
         * Ghana mobile money answers `pay_offline`: the customer approves on the handset and
         * the outcome reaches us by webhook. Paystack can also answer `success` outright, and
         * it is tempting to mark the order paid right here — but that would put a second
         * writer on `orders.is_paid` alongside `PaymentRecorder`, which owns the row lock, the
         * amount check and the idempotency. One writer, always. The tracking page's verify
         * call fires on arrival and settles it a beat later through the path that checks.
         *
         * `failed` is the one status worth acting on, because there is nothing to wait for.
         */
        $status = is_string($data['status'] ?? null) ? $data['status'] : 'pay_offline';

        if ($status === 'failed') {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'prompt_expires_at' => null,
            ]);

            return PaymentAttempt::refused(
                is_string($data['message'] ?? null) ? $data['message'] : 'charge_failed',
                $payment,
            );
        }

        return PaymentAttempt::prompted(
            $payment,
            is_string($data['display_text'] ?? null) ? $data['display_text'] : null,
        );
    }

    /**
     * A payment that will be settled without a gateway, so the lifecycle can be walked.
     *
     * ⚠️ THE ORDER IS MARKED BEFORE ANYTHING IS SETTLED, NOT AFTER.
     *
     * `orders.is_simulated` is set here, at the moment the attempt begins, rather than when
     * one succeeds. `Money\Insights` sums `orders`, and an abandoned test order that was
     * never flagged would still land in the "unpaid" figure — smaller than counting fake
     * revenue as real, but the same class of lie. Anything that has touched simulation is
     * out of the numbers from the first moment it does.
     *
     * ⚠️ IT REHEARSES THE REAL SHAPE. A simulated attempt comes back `prompted`, exactly like
     * a live one, and the tracking page shows the same waiting state with the rehearsal panel
     * on top of it. A rehearsal that skipped straight to a settle button would be rehearsing a
     * flow no customer will ever see — and the sad path is half the reason to have one.
     *
     * A number is not required here. There is no handset to reach, and refusing a rehearsal
     * for want of a wallet would make the mode unusable for exactly the walkthrough it exists
     * to support.
     */
    private function beginSimulated(Order $order, ?MomoInstruction $momo): PaymentAttempt
    {
        $payment = $this->attemptRow($order, $momo, provider: 'simulated', simulated: true);

        $order->is_simulated = true;
        $order->save();

        return PaymentAttempt::prompted($payment, 'Rehearsal — no prompt has been sent.');
    }

    /**
     * The attempt row, before anyone has been asked for money.
     *
     * Written first, deliberately: if the charge call times out, the reference we would have
     * used is already on disk and a settlement line naming it can still be matched to an
     * order. A row created only on success loses exactly the transactions that are hardest to
     * explain later.
     */
    private function attemptRow(Order $order, ?MomoInstruction $momo, string $provider, bool $simulated): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            // ⚠️ 'simulated', NOT 'paystack', for a rehearsal. A settlement import matching on
            // provider must never pick these up, and a row claiming to be from a gateway it
            // never reached is a lie the reconciliation screen would have to unpick later.
            'provider' => $provider,
            'is_simulated' => $simulated,
            'reference' => $this->reference($order),
            // Straight from the ORDER, never from a request. The amount charged is the amount
            // the server computed, and there is no path by which a client could influence it.
            'amount' => $order->total,
            'currency' => config('paystack.currency', 'GHS'),
            'status' => PaymentStatus::Pending->value,
            'momo_phone' => $momo?->phone,
            'momo_network' => $momo?->network->value,
            'prompt_expires_at' => now()->addSeconds((int) config('paystack.prompt_ttl', 300)),
        ]);
    }

    /**
     * Unique per attempt, and readable in a Paystack dashboard.
     *
     * The order number is in there so a settlement line can be matched to an order by eye,
     * which is what she will actually be doing at month end. The random tail is what makes
     * it an attempt rather than an order — and `payments.reference` is UNIQUE, so a
     * collision is refused by the database rather than silently reusing a transaction.
     */
    private function reference(Order $order): string
    {
        return 'mefs_'.$order->order_number.'_'.Str::lower(Str::random(12));
    }

    /**
     * Who Paystack addresses the transaction to.
     *
     * Paystack requires an email and most of her customers do not have one on file, so this
     * builds one per order. It is unique, it never pretends to be the customer's, and it does
     * not invent `ama@gmail.com`, which would be worse than being obviously synthetic.
     *
     * ⚠️ THE DOMAIN MUST BE REAL, AND THIS IS THE BUG THAT TAUGHT US SO.
     *
     * It used to be a hardcoded `@orders.mefs.local`. Paystack validates the address and
     * refuses a reserved TLD (RFC 6762), so every live charge came back 400 "Invalid Email
     * Address Passed" — which `begin()` correctly reports as unavailable, and which the
     * storefront correctly renders as "online payment isn't available right now". Three
     * correct steps built on one address that could never work, and the sentence on screen
     * pointed at the API keys, which were fine the whole time.
     *
     * The domain now comes from config, defaulting to the storefront's own host. See
     * `config/paystack.php`.
     */
    private function email(Order $order): string
    {
        $onFile = $order->customer?->email;

        if (is_string($onFile) && $onFile !== '') {
            return $onFile;
        }

        return 'order-'.Str::lower($order->order_number).'@'.config('paystack.order_email_domain');
    }
}
