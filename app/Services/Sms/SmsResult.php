<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * What happened to one message.
 *
 * ⚠️ THREE STATES, NOT A BOOLEAN (brief Law 7).
 *
 *   sent         the gateway took it
 *   refused      it will never work — a malformed number, a blocked recipient
 *   unavailable  we could not tell — no credentials, gateway down, request timed out
 *
 * The distinction is the point. `refused` is worth showing her ("that number is wrong");
 * `unavailable` is worth retrying and worth nobody's attention until it persists. Collapsing
 * them into `false` means a misconfigured gateway looks exactly like a bad phone number, and
 * the fix for each is completely different.
 *
 * ⚠️ AND NONE OF THE THREE BLOCKS A SALE. An SMS that did not send is a notification
 * problem; the order is already placed and paid for. `unjudged must not block a sale` is the
 * law, and here it means the caller logs and moves on.
 */
final readonly class SmsResult
{
    private function __construct(
        public string $status,
        public string $reason,
        /** The gateway's own id, when it gave one. For chasing a specific message later. */
        public ?string $reference = null,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self('sent', 'accepted', $reference);
    }

    /** Permanent. Retrying changes nothing. */
    public static function refused(string $reason): self
    {
        return new self('refused', $reason);
    }

    /** Unknown. Retrying might work; treating it as failure would be a guess. */
    public static function unavailable(string $reason): self
    {
        return new self('unavailable', $reason);
    }

    public function wasSent(): bool
    {
        return $this->status === 'sent';
    }

    /** Worth another go later. A refusal is not. */
    public function isRetryable(): bool
    {
        return $this->status === 'unavailable';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'reason' => $this->reason,
            'reference' => $this->reference,
        ], fn ($value) => $value !== null);
    }
}
