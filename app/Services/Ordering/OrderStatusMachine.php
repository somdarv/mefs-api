<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ THE ONLY THING THAT MOVES AN ORDER.
 *
 * Three rules, and each of them is a bug the original shipped:
 *
 *  1. **The transition is checked on the server.** The map in ../mefs/src/types/order.ts
 *     greys out buttons; it is not policy. A request that posts `completed` at a `received`
 *     order is refused here whatever its buttons looked like.
 *
 *  2. **Timestamps are written by the transition, never by the caller.** `accepted_at`
 *     comes from the server clock at the moment of the move. A caller that supplies its own
 *     is supplying the client's clock, and two clients disagreeing about when an order was
 *     accepted is not a dispute anybody can settle afterwards.
 *
 *  3. **Every change writes a history row, in the same transaction.** Not "afterwards", not
 *     "in an observer that might be disabled in tests" — in the transaction, so an order
 *     whose status moved and whose audit trail did not is not a state this table can reach.
 *
 * It also owns the other half of the capacity ledger: cancelling an order gives its
 * portions back. That lives here rather than in a controller because there are three ways
 * to reach `cancelled` — she cancels it, the customer's request is accepted, the unpaid
 * hold expires — and a release written at each of them is a release forgotten at one.
 */
final class OrderStatusMachine
{
    public function __construct(private readonly PortionLedger $ledger) {}

    /**
     * Move an order to `$to`, or throw.
     *
     * Returns the same instance, updated, so a controller can respond with it directly.
     *
     * @throws IllegalTransition
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        $from = $order->status;

        if ($from === $to) {
            // Idempotent rather than an error. Two staff tapping "Accept" at once is an
            // ordinary Friday, and the second tap should not be a red toast.
            return $order;
        }

        if (! $from->canMoveTo($to)) {
            throw IllegalTransition::between($from, $to);
        }

        if (! $to->isAllowedFor($order->order_type)) {
            throw IllegalTransition::wrongOrderType($to, $order);
        }

        return DB::transaction(function () use ($order, $from, $to, $actor, $note): Order {
            $order->status = $to;

            if ($column = $to->timestampColumn()) {
                // Set once. A re-entered state — which the map does not currently allow,
                // but might one day — must not rewrite when the food was first ready.
                $order->{$column} ??= now();
            }

            // Where a rejected cancellation returns to. Stored on the way IN, because by
            // the time she rejects it the previous status is gone (brief §5.4).
            if ($to === OrderStatus::CancelRequested) {
                $order->cancel_previous_status = $from->value;
            }

            $order->save();

            // The pot gets its portions back. Only on the way INTO cancelled, and the
            // `$from === $to` guard above is what stops a replayed cancellation releasing
            // them twice.
            if ($to === OrderStatus::Cancelled) {
                $this->ledger->release($order->load('items'));
            }

            $this->record($order, $from, $to, $actor, $note);

            return $order;
        });
    }

    /**
     * Reject a cancellation request: put the order back exactly where it was.
     *
     * ⚠️ NOT A TRANSITION, AND DELIBERATELY NOT ROUTED THROUGH ONE. The destination comes
     * from `cancel_previous_status` — a stored column — rather than from a request body or
     * from a guess about where the order "probably was". Guessing is how one ends up back
     * in `received` after the food is already cooked (brief §5.4).
     *
     * @throws IllegalTransition
     */
    public function rejectCancellation(Order $order, ?User $actor = null, ?string $note = null): Order
    {
        if ($order->status !== OrderStatus::CancelRequested) {
            throw IllegalTransition::notAwaitingCancellation($order);
        }

        $previous = $order->cancel_previous_status === null
            ? null
            : OrderStatus::tryFrom($order->cancel_previous_status);

        if ($previous === null) {
            // Unreachable via `transition()`, which always stores it. Refusing beats
            // inventing a destination: an order stuck in `cancel_requested` is visible and
            // fixable, an order silently reset to `received` is neither.
            throw IllegalTransition::noRestorePoint($order);
        }

        return DB::transaction(function () use ($order, $previous, $actor, $note): Order {
            $from = $order->status;

            $order->status = $previous;
            $order->cancel_previous_status = null;
            $order->cancel_requested_by = null;
            $order->cancel_requested_at = null;
            $order->cancel_request_reason = null;

            $order->save();

            $this->record($order, $from, $previous, $actor, $note ?? 'Cancellation rejected');

            return $order;
        });
    }

    /**
     * The first history row, written by `OrderCreator` as the order is created.
     *
     * `from_status` is null — there was nowhere before. Separate from `transition()`
     * because an order arriving at `received` did not move there from anything, and calling
     * a transition with a null origin would need the map to grow a case that means "birth".
     */
    public function recordPlacement(Order $order, ?User $actor = null, ?string $note = null): void
    {
        $this->record($order, null, $order->status, $actor, $note);
    }

    private function record(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $actor,
        ?string $note,
    ): void {
        OrderStatusChange::query()->create([
            'order_id' => $order->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            // Snapshot beside the key: staff leave, and "who cancelled this" must still
            // have an answer when the account is gone.
            'actor_name' => $actor?->name,
            'note' => $note,
        ]);
    }
}
