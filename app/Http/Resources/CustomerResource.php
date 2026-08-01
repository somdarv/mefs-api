<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer, as they see themselves.
 *
 * ⚠️ `internal_notes` IS ABSENT AND MUST STAY ABSENT. It is the staff field where "always
 * late, call ahead" gets written, and it is `$hidden` on the model as well — two layers,
 * because this resource is the one most likely to be reached for by a future staff endpoint
 * that wants "the customer" and gets more than it meant to.
 *
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,

            // Offered at checkout, never authority — an order snapshots what was used.
            'default_address' => $customer->default_address,
            'default_area' => $customer->default_area,
            'default_delivery_note' => $customer->default_delivery_note,

            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}
