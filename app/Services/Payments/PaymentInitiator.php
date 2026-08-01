<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
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

    private function email(Order $order): string
    {
        return $order->customer?->email
            ?: 'order-'.Str::lower($order->order_number).'@orders.mefs.local';
    }
}
