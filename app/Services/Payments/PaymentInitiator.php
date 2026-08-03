<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Start a payment for an order.
 *
 * ⚠️ ONE `payments` ROW PER ATTEMPT, NOT PER ORDER. A customer who opens Paystack, wanders
 * off and comes back leaves two rows here and only one of them completed. Modelling it as
 * one row per order means the second attempt overwrites the first, and the reconciliation
 * screen in M6 then cannot explain a settlement that mentions a reference the system has
 * forgotten.
 *
 * ⚠️ AND IT NEVER THROWS. No keys, gateway down, timeout — all of them come back as
 * `unavailable`, the order stands, and she arranges payment the way she does today. Law 7:
 * a check that cannot be evaluated must not block a sale. That is not a hypothetical here;
 * it is the state the system is in until keys are supplied.
 */
final class PaymentInitiator
{
    public function __construct(private readonly PaystackClient $paystack) {}

    public function begin(Order $order): PaymentAttempt
    {
        if ($order->is_paid) {
            return PaymentAttempt::refused('already_paid');
        }

        if (SystemSetting::get('payment_mode', 'live') === 'simulate') {
            return $this->beginSimulated($order);
        }

        if (! $this->paystack->isConfigured()) {
            // The current state of the world. Not an error, not logged as one — the shop
            // works, orders arrive unpaid, and the checkout screen says she will call.
            return PaymentAttempt::unavailable('not_configured');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'provider' => 'paystack',
            'reference' => $this->reference($order),
            // Straight from the ORDER, never from a request. The amount charged is the
            // amount the server computed, and there is no path by which a client could
            // influence it.
            'amount' => $order->total,
            'currency' => config('paystack.currency', 'GHS'),
            'status' => PaymentStatus::Pending->value,
        ]);

        $result = $this->paystack->initialize(
            reference: $payment->reference,
            amountMinor: $payment->amount,
            // Paystack requires an email and most of her customers do not have one on file.
            // A per-order address on our own domain is deliverable-shaped, unique, and never
            // pretends to be the customer's — inventing `ama@gmail.com` would be worse.
            email: $this->email($order),
            currency: $payment->currency,
            callbackUrl: rtrim(config('paystack.callback_url'), '/').'/'.$order->tracking_token,
            metadata: [
                'order_number' => $order->order_number,
                'order_id' => $order->id,
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
                'payload' => ['error' => $reason],
            ]);

            return $retryable
                ? PaymentAttempt::unavailable($reason, $payment)
                : PaymentAttempt::refused($reason, $payment);
        }

        $payment->update(['payload' => $result['data'] ?? []]);

        return PaymentAttempt::started($payment, (string) $result['authorization_url']);
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
     * The checkout URL points at our own storefront rather than Paystack's. That page offers
     * "paid" and "failed", because the whole point of a rehearsal is to rehearse the sad
     * path too — an order that cannot fail to pay has not been tested.
     */
    private function beginSimulated(Order $order): PaymentAttempt
    {
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            // NOT 'paystack'. A settlement import matching on provider must never pick these
            // up, and a row claiming to be from a gateway it never reached is a lie the
            // reconciliation screen would have to unpick later.
            'provider' => 'simulated',
            'is_simulated' => true,
            'reference' => $this->reference($order),
            'amount' => $order->total,
            'currency' => config('paystack.currency', 'GHS'),
            'status' => PaymentStatus::Pending->value,
        ]);

        $order->is_simulated = true;
        $order->save();

        return PaymentAttempt::started(
            $payment,
            rtrim((string) config('paystack.callback_url'), '/')
                .'/'.$order->tracking_token.'?simulate='.$payment->reference,
        );
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
     * refuses a reserved TLD (RFC 6762), so every live `initialize` came back 400 "Invalid
     * Email Address Passed" — which `begin()` correctly reports as unavailable, and which the
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
