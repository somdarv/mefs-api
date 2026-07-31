<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use RuntimeException;

/**
 * The order was not created, and this says why.
 *
 * ⚠️ IT CARRIES THE GATE'S VERDICT, NOT A SENTENCE.
 *
 * When the refusal came from `CycleGate` the whole `OrderingState` travels with it —
 * status, machine-readable reason, customer-facing message, and how many are left. The
 * controller puts that in the envelope, the storefront switches on `reason` to decide
 * whether to offer another day or another dish, and a bug report says `sold_out:
 * item_capacity` instead of "it wouldn't let me order".
 *
 * That is Law 6 carried through to the caller: a refusal that cannot be told apart from a
 * failure is a refusal nobody can act on.
 */
final class OrderRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?OrderingState $state = null,
        /** Which basket line caused it, when one did. */
        public readonly ?int $menuItemOptionId = null,
    ) {
        parent::__construct($message);
    }

    /** The gate said no. Its message is already customer-ready. */
    public static function byGate(OrderingState $state, ?int $menuItemOptionId = null): self
    {
        return new self($state->message, $state->reason, $state, $menuItemOptionId);
    }

    public static function emptyBasket(): self
    {
        return new self('There is nothing in this basket.', 'empty_basket');
    }

    public static function unknownOption(int $menuItemOptionId): self
    {
        return new self(
            'Something in your basket is no longer on sale. Remove it and try again.',
            'unknown_option',
            null,
            $menuItemOptionId,
        );
    }

    /** A meal with no cooking day, or a shipment with one. The CHECK constraint's rule. */
    public static function fulfilmentMismatch(string $message): self
    {
        return new self($message, 'fulfilment_mismatch');
    }

    public static function invalidContact(string $message): self
    {
        return new self($message, 'invalid_contact');
    }

    public static function noBranch(): self
    {
        return new self('The kitchen is not set up to take orders.', 'no_branch');
    }

    /** @return array<string, list<string>> */
    public function toErrorBag(): array
    {
        return ['order' => [$this->getMessage()]];
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return array_filter([
            'reason' => $this->reason,
            'ordering' => $this->state?->toArray(),
            'menu_item_option_id' => $this->menuItemOptionId,
        ], fn ($v) => $v !== null);
    }
}
