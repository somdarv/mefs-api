<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order is in its life.
 *
 * ⚠️ THIS ENUM IS THE POLICY. The map in ../mefs/src/types/order.ts greys out buttons and
 * is nothing more — a client that posts `completed` at a `received` order is refused here,
 * whatever its buttons looked like.
 *
 * Kept in sync with TWO check constraints: `orders_status_check` and
 * `order_status_history_to_check`. The original widened the first and forgot the second,
 * and the failure surfaced as a 500 on the audit write long after the status change looked
 * like it had worked (brief trap §10.11).
 */
enum OrderStatus: string
{
    /** Placed. For a customer order that also means paid — an unpaid one holds nothing. */
    case Received = 'received';

    /** She has seen it and committed to cooking it. */
    case Accepted = 'accepted';

    case Preparing = 'preparing';

    /** Cooked. Not yet handed to anyone — the next step depends on the order type. */
    case Ready = 'ready';

    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';

    /** Done. Terminal. */
    case Completed = 'completed';

    /**
     * The customer asked to cancel and she has not answered yet (brief §5.4).
     *
     * A holding state, not a decision: accepting it moves to `cancelled`, rejecting it
     * restores `cancel_previous_status` — which is why that column is STORED rather than
     * re-derived. Guessing where the order "probably was" is how one ends up back in
     * `received` after the food is already cooked.
     */
    case CancelRequested = 'cancel_requested';

    case Cancelled = 'cancelled';

    /**
     * Legal next states, ignoring order type.
     *
     * `cancel_requested` leads only to `cancelled` here. Rejection is not a transition — it
     * is a restore, and it goes through `OrderStatusMachine::rejectCancellation()` so that
     * the destination comes from the stored column rather than from a request body.
     *
     * @return list<self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Received => [self::Accepted, self::CancelRequested, self::Cancelled],
            self::Accepted => [self::Preparing, self::CancelRequested, self::Cancelled],
            self::Preparing => [self::Ready, self::CancelRequested, self::Cancelled],
            self::Ready => [self::ReadyForPickup, self::OutForDelivery, self::Completed],
            self::ReadyForPickup => [self::Completed],
            self::OutForDelivery => [self::Delivered],
            self::Delivered => [self::Completed],
            self::CancelRequested => [self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->transitionsTo(), true);
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /**
     * Handover states belong to one kind of order.
     *
     * `ready` fans out to three places, and only one of them is right for a given order: a
     * pickup order can never be `out_for_delivery`, and a courier never leaves a shipped
     * jollof base "ready for pickup". Without this the transition map alone would let a
     * mis-tapped button put an order into a state its own screens cannot represent.
     */
    public function isAllowedFor(OrderType $type): bool
    {
        return match ($this) {
            self::ReadyForPickup => $type === OrderType::Pickup,
            self::OutForDelivery, self::Delivered => $type !== OrderType::Pickup,
            default => true,
        };
    }

    /**
     * The column this transition stamps, or null when it stamps nothing.
     *
     * ⚠️ WRITTEN BY THE TRANSITION, NEVER BY THE CALLER. A caller that passes its own
     * `accepted_at` is passing the client's clock, and two clients disagreeing about when
     * an order was accepted is not a dispute anyone can settle afterwards.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Accepted => 'accepted_at',
            self::Preparing => 'started_at',
            self::Ready => 'ready_at',
            self::Completed => 'completed_at',
            default => null,
        };
    }

    /** Does an order in this state still hold kitchen capacity? */
    public function holdsCapacity(): bool
    {
        return $this !== self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Accepted => 'Accepted',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::ReadyForPickup => 'Ready for pickup',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::CancelRequested => 'Cancellation requested',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
