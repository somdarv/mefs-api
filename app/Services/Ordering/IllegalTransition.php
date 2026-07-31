<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Models\Order;
use RuntimeException;

/**
 * A refused status change.
 *
 * Carries the from/to pair rather than just a sentence, so the controller can put it in the
 * envelope's `errors` and a bug report says which move was refused. "Invalid transition" on
 * its own is unfalsifiable — the same complaint the brief makes about bare booleans.
 */
final class IllegalTransition extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?OrderStatus $from = null,
        public readonly ?OrderStatus $to = null,
    ) {
        parent::__construct($message);
    }

    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        $allowed = array_map(fn (OrderStatus $s) => $s->value, $from->transitionsTo());

        return new self(
            $from->isTerminal()
                ? "An order that is {$from->label()} cannot move again."
                : "An order cannot go from {$from->value} to {$to->value}. Allowed: ".
                  (implode(', ', $allowed) ?: 'nothing').'.',
            $from,
            $to,
        );
    }

    public static function wrongOrderType(OrderStatus $to, Order $order): self
    {
        return new self(
            "A {$order->order_type->value} order cannot be {$to->label()}.",
            $order->status,
            $to,
        );
    }

    public static function notAwaitingCancellation(Order $order): self
    {
        return new self(
            'There is no cancellation request to reject on this order.',
            $order->status,
        );
    }

    public static function noRestorePoint(Order $order): self
    {
        return new self(
            'This order has no stored status to return to.',
            $order->status,
        );
    }

    /** @return array<string, list<string>> */
    public function toErrorBag(): array
    {
        return ['status' => [$this->getMessage()]];
    }
}
