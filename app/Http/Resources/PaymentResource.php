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
