<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderingStatus;
use Carbon\CarbonImmutable;

/**
 * The answer to "may someone order for this date, right now, and why?"
 *
 * ⚠️ A STATE AND A REASON, NEVER A BARE BOOLEAN.
 *
 * `false` tells the customer nothing — "closed" and "not open yet" and "sold out" want
 * three different sentences and lead to three different actions. It also tells us nothing
 * in a bug report: "ordering was closed" is unfalsifiable, "closed: cutoff_passed" is not.
 *
 * This is the brief's Law 6 applied to ordering. The states are four rather than three
 * because ordering genuinely has four, but the principle is the same one: a gate reports
 * *why* it decided, and a caller can tell a refusal from a failure to evaluate.
 */
final readonly class OrderingState
{
    private function __construct(
        public OrderingStatus $status,
        /** Machine-readable, stable, safe to switch on. */
        public string $reason,
        /** What to show the customer. Never leaks a staff-only note. */
        public string $message,
        public ?CarbonImmutable $opensAt = null,
        public ?CarbonImmutable $closesAt = null,
        /** Null when uncapped. Zero means sold out. */
        public ?int $remaining = null,
    ) {}

    public static function open(
        CarbonImmutable $closesAt,
        string $reason,
        string $message,
        ?int $remaining = null,
    ): self {
        return new self(OrderingStatus::Open, $reason, $message, null, $closesAt, $remaining);
    }

    public static function notYetOpen(CarbonImmutable $opensAt, string $message): self
    {
        return new self(OrderingStatus::NotYetOpen, 'before_window', $message, $opensAt);
    }

    public static function closed(string $reason, string $message): self
    {
        return new self(OrderingStatus::Closed, $reason, $message);
    }

    public static function soldOut(string $reason, string $message): self
    {
        return new self(OrderingStatus::SoldOut, $reason, $message, null, null, 0);
    }

    /** The only question the checkout path asks. */
    public function allowsOrdering(): bool
    {
        return $this->status === OrderingStatus::Open;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'message' => $this->message,
            'opens_at' => $this->opensAt?->toIso8601String(),
            'closes_at' => $this->closesAt?->toIso8601String(),
            'remaining' => $this->remaining,
        ];
    }
}
