<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment ATTEMPT — see `Payment`. A customer who abandons Paystack and comes back
 * leaves two rows, and only one of them completed.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_number' => $payment->order?->order_number,
            'contact_name' => $payment->order?->contact_name,

            'provider' => $payment->provider,
            'reference' => $payment->reference,
            'channel' => $payment->channel,

            /*
             * ⚠️ WHICH WALLET WAS CHARGED, WHICH IS NOT `contact_name`'s NUMBER. Storing this
             * and never showing it would leave "which number did they try?" unanswerable — and
             * that is the first question about a payment that did not land, which is the only
             * kind of payment anybody opens this screen to look at.
             *
             * Null on an attempt that never named one: an admin-entered order paid later, or a
             * rehearsal, which has no handset to reach.
             */
            'momo_phone' => $payment->momo_phone,
            'momo_network' => $payment->momo_network?->value,
            'network_label' => $payment->momo_network?->label(),

            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),

            'amount' => $payment->amount,
            'currency' => $payment->currency,

            /*
             * ⚠️ NULL MEANS UNKNOWN, NOT ZERO, for both of these — and the whole money
             * screen turns on the difference. `fee` is null until Paystack tells us what
             * they took; `settled_amount` is null until a settlement file says what landed.
             * A client that renders either as ₵0.00 is asserting something nobody knows.
             */
            'fee' => $payment->fee,
            'settled_amount' => $payment->settled_amount,
            'settled_at' => $payment->settled_at?->toIso8601String(),

            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }
}
