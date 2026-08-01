<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ⚠️ STAFF-ONLY. NEVER RENDERED ON A CUSTOMER SURFACE.
 *
 * A customer sees a verdict on the code they typed — `PromoVerdict` — and nothing else.
 * Serialising the promo itself to the storefront would publish the usage limits, the
 * minimum spend and the fact that a code exists at all, which together are enough to work
 * out which codes are worth guessing at.
 *
 * @mixin Promo
 */
final class PromoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Promo $promo */
        $promo = $this->resource;

        return [
            'id' => $promo->id,
            'code' => $promo->code,
            'description' => $promo->description,

            'type' => $promo->type->value,
            'type_label' => $promo->type->label(),
            'value' => $promo->value,
            'max_discount' => $promo->max_discount,
            'min_subtotal' => $promo->min_subtotal,

            'scope' => $promo->scope->value,
            'scope_label' => $promo->scope->label(),
            'first_order_only' => $promo->first_order_only,

            'starts_at' => $promo->starts_at?->toIso8601String(),
            'ends_at' => $promo->ends_at?->toIso8601String(),

            /*
             * ⚠️ NULL IS UNLIMITED, NOT ZERO — and the client must render it that way. A
             * limit of null shown as "0 uses left" is the opposite of what it means.
             */
            'usage_limit' => $promo->usage_limit,
            'usage_limit_per_customer' => $promo->usage_limit_per_customer,
            'times_used' => $promo->times_used,
            'redemptions_count' => $promo->redemptions_count ?? null,

            'is_active' => $promo->is_active,

            /*
             * `is_active` is her switch; `is_live` is whether it would actually work right
             * now. A code that is active but starts on Friday, or active but fully used,
             * looks on for as long as the list only shows the switch — which is how a
             * customer ends up being told about a code that refuses them.
             */
            'is_live' => $promo->is_active
                && $promo->hasStarted()
                && ! $promo->hasEnded()
                && ! $promo->isExhausted(),

            'created_at' => $promo->created_at?->toIso8601String(),
        ];
    }
}
