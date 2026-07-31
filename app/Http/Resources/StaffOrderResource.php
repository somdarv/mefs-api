<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderStatusChange;
use Illuminate\Http\Request;

/**
 * The back-office view: everything the customer sees, plus what only she does.
 *
 * A subclass rather than a flag on `OrderResource`. The staff fields have to be reached for
 * deliberately, and the customer resource cannot grow one by someone flipping a default.
 */
final class StaffOrderResource extends OrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return array_merge(parent::toArray($request), [
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,

            'internal_notes' => $order->internal_notes,
            'created_by' => $order->created_by,

            // Who keeps the delivery fee, and what the order is worth once the courier's
            // share is taken out. Revenue is never gross (brief §5.3).
            'delivery_fee_collection' => $order->delivery_fee_collection,
            'courier_name' => $order->courier_name,
            'courier_reference' => $order->courier_reference,
            'revenue_total' => $order->revenueTotal(),

            // Departure #6. When this unpaid order stops holding its slot.
            'slot_hold_expires_at' => $order->slot_hold_expires_at?->toIso8601String(),
            'hold_expired' => $order->hold_expired,

            // Stored, not re-derived — where a rejected cancellation returns to.
            'cancel_previous_status' => $order->cancel_previous_status,

            // What she may do next. The client's own map is for greying out buttons; this
            // is the server telling it what it would actually accept.
            'allowed_transitions' => array_values(array_map(
                fn ($s) => $s->value,
                array_filter(
                    $order->status->transitionsTo(),
                    fn ($s) => $s->isAllowedFor($order->order_type),
                ),
            )),

            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => $order->statusHistory->map(fn (OrderStatusChange $c) => [
                    'from' => $c->from_status?->value,
                    'to' => $c->to_status->value,
                    'to_label' => $c->to_status->label(),
                    // Snapshot beside the key: staff leave, and the question still has an
                    // answer afterwards.
                    'actor_name' => $c->actor_name,
                    'note' => $c->note,
                    'at' => $c->created_at->toIso8601String(),
                ])->all(),
            ),
        ]);
    }
}
