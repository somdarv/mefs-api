<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * THE order shape, customer-safe (brief Law 1). Mirrored by `Order` in
 * ../mefs/src/types/order.ts, which every surface consumes — no per-module order interface.
 *
 * ⚠️ WHAT IS ABSENT IS THE SPECIFICATION.
 *
 * `internal_notes`, `created_by`, the kitchen note, the staff member who moved the status,
 * `slot_hold_expires_at` — none of it is here, and none of it can be added by accident,
 * because the staff view is a SUBCLASS that adds fields rather than a flag that removes
 * them. A boolean like `$includeStaffFields` defaults wrong exactly once and leaks
 * thereafter; a subclass has to be reached for.
 *
 * Every money field is an integer count of pesewas, computed server-side.
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,

            // Dictatable over the phone. An identifier, NOT a credential — the token below
            // is what URLs use (brief §5.6).
            'order_number' => $order->order_number,
            'tracking_token' => $order->tracking_token,

            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'is_terminal' => $order->status->isTerminal(),

            'order_type' => $order->order_type->value,
            'source' => $order->source->value,
            'is_manual_entry' => $order->is_manual_entry,

            // Null on a pantry-only order. Shelf-stable goods are bound to no cooking day,
            // and the database refuses any other combination (orders_fulfilment_binding_check).
            'fulfil_date' => $order->fulfil_date?->toDateString(),
            'order_cycle_id' => $order->order_cycle_id,
            'cycle_day_id' => $order->cycle_day_id,

            // Snapshot of what the customer saw. A rename must not rewrite this receipt.
            'branch' => $order->branchSnapshot(),

            'subtotal' => $order->subtotal,
            'service_charge' => $order->service_charge,
            'delivery_fee' => $order->delivery_fee,
            'discount' => $order->discount,
            'total' => $order->total,
            'promo_code' => $order->promo_code,

            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status->value,
            'is_paid' => $order->is_paid,

            'contact_name' => $order->contact_name,
            'contact_phone' => $order->contact_phone,
            'delivery_address' => $order->delivery_address,
            'delivery_area' => $order->delivery_area,
            'gps_code' => $order->gps_code,
            'delivery_note' => $order->delivery_note,

            'cancel_requested_by' => $order->cancel_requested_by,
            'cancel_requested_at' => $order->cancel_requested_at?->toIso8601String(),
            'cancel_request_reason' => $order->cancel_request_reason,

            'placed_at' => $order->placed_at?->toIso8601String(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'started_at' => $order->started_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            // The customer sees THAT it moved and when, never who moved it.
            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => $order->statusHistory->map(fn (OrderStatusChange $c) => [
                    'from' => $c->from_status?->value,
                    'to' => $c->to_status->value,
                    'to_label' => $c->to_status->label(),
                    'at' => $c->created_at->toIso8601String(),
                ])->all(),
            ),
        ];
    }
}
