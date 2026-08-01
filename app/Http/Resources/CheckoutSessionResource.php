<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CheckoutSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A basket, priced.
 *
 * ⚠️ THE QUOTE COMES FROM `PriceCalculator`, THE SAME FUNCTION THAT PRICES THE ORDER.
 *
 * Not a second implementation "for display" — that is how a checkout screen ends up showing
 * ₵58.00 and charging ₵60.00, and the customer is right and you cannot explain it. What the
 * customer sees and what they are charged are the same function of the same inputs, so they
 * cannot drift.
 *
 * Quotes come per order type rather than for one chosen type, because "delivery adds ₵20" is
 * something the checkout screen needs to say before the customer has chosen anything.
 *
 * @mixin CheckoutSession
 */
final class CheckoutSessionResource extends JsonResource
{
    /**
     * @param  array{lines: list<array<string, mixed>>, quotes: list<array<string, mixed>>, ordering: array<string, mixed>|null, promo: array<string, mixed>}  $priced
     */
    public function __construct(CheckoutSession $resource, private readonly array $priced)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The capability for this basket. Long and random, like the order tracking
            // token, and for the same reason — it is held by someone with no account.
            'token' => $this->token,
            'guest_session' => $this->guest_session,

            'status' => $this->status->value,
            'expires_at' => $this->expires_at->toIso8601String(),

            'order_cycle_id' => $this->order_cycle_id,
            'cycle_day_id' => $this->cycle_day_id,
            'fulfil_date' => $this->cycleDay?->date->toDateString(),

            // Priced server-side. A line carries what it costs; it never said so on the way in.
            'lines' => $this->priced['lines'],

            // Whether this basket may become an order at all, WITH THE REASON — so the
            // screen can say "orders for Wednesday closed on Tuesday at 6pm" rather than
            // greying out a button and leaving the customer to guess.
            'ordering' => $this->priced['ordering'],

            'quotes' => $this->priced['quotes'],

            /*
             * The verdict on the code, if one was applied — `outcome`, `reason`, a message
             * written for the customer, and what it took off.
             *
             * ⚠️ THE VERDICT, NOT THE PROMO. A customer never sees the promo row: its usage
             * limits, its minimum spend, and the fact that it exists at all are together
             * enough to work out which codes are worth guessing at.
             *
             * ⚠️ AND IT IS ADVISORY, like every number here. This is resolved with no phone
             * number, because the basket has none yet — so "first order only" and the
             * per-customer limit cannot be evaluated until confirm.
             */
            'promo' => $this->priced['promo'],
        ];
    }
}
