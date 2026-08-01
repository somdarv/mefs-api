<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What applying a gateway message actually did.
 *
 * `ignored` is a first-class result rather than a silent return, because "we saw this
 * already" and "we did nothing because something is wrong" look identical from the outside
 * and want completely different responses from a human.
 */
final readonly class PaymentOutcome
{
    private function __construct(
        public string $status,
        public string $reason,
        public ?int $expected = null,
        public ?int $charged = null,
    ) {}

    public static function recorded(): self
    {
        return new self('recorded', 'applied');
    }

    /** A replay, or a message about something that no longer exists. Not a failure. */
    public static function ignored(string $reason): self
    {
        return new self('ignored', $reason);
    }

    /** ⚠️ The amount charged is not the amount owed. Nothing was marked paid. */
    public static function mismatch(int $expected, int $charged): self
    {
        return new self('mismatch', 'amount_mismatch', $expected, $charged);
    }

    public function wasApplied(): bool
    {
        return $this->status === 'recorded';
    }
}
