<?php

declare(strict_types=1);

namespace App\Services\Money;

/**
 * What happened to one line of a settlement file.
 *
 * Three-state rather than a boolean (brief Law 6): "settled", "we have never heard of this
 * reference" and "this contradicts what we already recorded" are three different things,
 * and only the third is a problem she has to look at. A boolean would collapse the last two
 * into "failed" and bury the one that matters under the many that don't.
 */
final readonly class SettlementRow
{
    private function __construct(
        public string $outcome,
        public string $reference,
        public ?int $settledAmount,
        public ?string $note,
    ) {}

    /** Matched a payment and written. */
    public static function settled(string $reference, int $amount): self
    {
        return new self('settled', $reference, $amount, null);
    }

    /**
     * No payment carries this reference.
     *
     * Ordinary, not an error: a settlement batch can contain transactions from before this
     * system existed, or from a second Paystack integration. Reported and skipped.
     */
    public static function unknown(string $reference): self
    {
        return new self('unknown_reference', $reference, null, 'No payment with this reference.');
    }

    /**
     * We already recorded a different figure for this reference.
     *
     * ⚠️ NOT OVERWRITTEN. A settled amount that silently changes on re-import is a number
     * nobody can rely on, and re-importing the same file is the most likely thing to happen
     * to it. She is told and decides.
     */
    public static function conflict(string $reference, int $existing, int $incoming): self
    {
        return new self('conflict', $reference, $incoming, sprintf(
            'Already settled at %d pesewas; the file says %d. Left unchanged.',
            $existing,
            $incoming,
        ));
    }

    /** Already recorded at exactly this figure. Re-importing a file is a no-op. */
    public static function unchanged(string $reference, int $amount): self
    {
        return new self('unchanged', $reference, $amount, null);
    }

    /**
     * The settlement is larger than the charge.
     *
     * A payout cannot exceed what was taken — fees only ever come off. This means the file
     * is the wrong one, the column mapping is wrong, or the units are wrong, and writing it
     * would put an impossible number in the ledger.
     */
    public static function implausible(string $reference, int $charged, int $incoming): self
    {
        return new self('implausible', $reference, $incoming, sprintf(
            'Settled %d is more than the %d charged. A payout is never larger than the charge.',
            $incoming,
            $charged,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reference' => $this->reference,
            'settled_amount' => $this->settledAmount,
            'note' => $this->note,
        ];
    }
}
