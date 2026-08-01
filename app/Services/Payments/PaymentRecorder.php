<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Apply what the gateway says happened to an order.
 *
 * ⚠️ IDEMPOTENT, BECAUSE PAYSTACK RETRIES. Assume every webhook arrives at least twice and
 * sometimes twice at once. Two defences, and they are different:
 *
 *   the UNIQUE index on `payments.reference`  stops a replay creating a SECOND attempt row
 *   the row lock plus the status check here   stops a replay re-applying the SAME one
 *
 * Neither is an `if` in a controller that two concurrent workers can both pass (brief §5.7).
 *
 * ⚠️ AND IT CHECKS THE AMOUNT. A callback that says ₵5.00 against an order for ₵80.00 does
 * not mark that order paid, however valid its signature. A signature proves the message came
 * from Paystack; it does not prove the message is about the money we asked for.
 */
final class PaymentRecorder
{
    /**
     * @param  array<string, mixed>  $payload  the gateway's `data` object, kept whole
     */
    public function succeeded(Payment $payment, array $payload): PaymentOutcome
    {
        return DB::transaction(function () use ($payment, $payload): PaymentOutcome {
            // Re-read under a lock. Two deliveries landing together both saw `pending`
            // before this line; only one of them sees it after.
            $fresh = Payment::query()->lockForUpdate()->find($payment->id);

            if ($fresh === null) {
                return PaymentOutcome::ignored('payment_missing');
            }

            if ($fresh->status === PaymentStatus::Completed) {
                // The ordinary case for a retry. Not an error and not worth a log line at
                // warning — it is the system working as designed.
                return PaymentOutcome::ignored('already_recorded');
            }

            $charged = (int) ($payload['amount'] ?? 0);

            if ($charged !== $fresh->amount) {
                // ⚠️ NEVER mark this paid. A mismatch is either a partial payment, a
                // tampered callback, or our own bug — and all three want a human, not an
                // order silently marked settled for the wrong money.
                Log::error('Paystack amount mismatch; order NOT marked paid', [
                    'reference' => $fresh->reference,
                    'expected' => $fresh->amount,
                    'charged' => $charged,
                ]);

                $fresh->update(['payload' => $payload]);

                return PaymentOutcome::mismatch($fresh->amount, $charged);
            }

            $fresh->update([
                'status' => PaymentStatus::Completed->value,
                'channel' => is_string($payload['channel'] ?? null) ? $payload['channel'] : null,
                // What the gateway kept. Gross is not revenue, and this is the column the
                // money dashboard in M6 turns on.
                'fee' => isset($payload['fees']) ? (int) $payload['fees'] : null,
                'paid_at' => isset($payload['paid_at'])
                    ? CarbonImmutable::parse((string) $payload['paid_at'])
                    : now(),
                // The raw callback, whole. A dispute is unarguable without it.
                'payload' => $payload,
            ]);

            $order = $fresh->order;

            if ($order !== null) {
                $order->is_paid = true;
                $order->payment_status = PaymentStatus::Completed->value;
                if (is_string($payload['channel'] ?? null)) {
                    $order->payment_method = $payload['channel'];
                }

                // ⚠️ THE SLOT IS NO LONGER ON A CLOCK. A paid order must never be swept by
                // the hold-expiry job — `scopeHoldExpired` already excludes paid orders, but
                // clearing this makes the order's own record say so rather than relying on
                // one scope staying correct forever.
                $order->slot_hold_expires_at = null;

                $order->save();
            }

            return PaymentOutcome::recorded();
        });
    }

    /**
     * A failed or abandoned attempt.
     *
     * ⚠️ THE ORDER STAYS `pending`, NOT `failed`. One attempt failing says nothing about the
     * order — the customer can try again in the next thirty seconds, and marking the order
     * failed would show her a dead sale that is about to succeed. `orders.payment_status`
     * has no `abandoned` value at all, deliberately; that word only applies to an attempt.
     */
    public function failed(Payment $payment, array $payload, PaymentStatus $status = PaymentStatus::Failed): PaymentOutcome
    {
        if ($payment->status === PaymentStatus::Completed) {
            // A late "failed" for a reference that already succeeded. Never downgrade a
            // completed payment on the strength of a message that arrived out of order.
            return PaymentOutcome::ignored('already_recorded');
        }

        $payment->update(['status' => $status->value, 'payload' => $payload]);

        return PaymentOutcome::recorded();
    }
}
