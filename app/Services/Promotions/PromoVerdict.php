<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Models\Promo;

/**
 * What happened when a code was checked, and why.
 *
 * ⚠️ A REASON, NEVER A BARE BOOLEAN — the same rule `OrderingState` follows.
 *
 * "This code isn't valid" is the message that generates a phone call. "This code runs out on
 * Friday" and "this code is for the pantry range" and "spend ₵50 to use this one" are three
 * different facts, each of which tells the customer what to do next, and each of which tells
 * us something different when it turns up in a bug report.
 *
 * ── ON THE MISSING FOURTH STATE ───────────────────────────────────────────────
 *
 * Law 7 asks for `ok` / `refuse` / `unjudged` on any gate that **can fail to evaluate**, and
 * there is deliberately no `unjudged` here. Every input this check reads is a local row
 * inside the order's own transaction — there is no provider to be down and no timeout to
 * swallow. If the database is unreachable there is no order to discount either. Adding a
 * fourth state that nothing can ever produce would be ceremony, and a state with no
 * producer is a state nobody tests.
 *
 * `none` is not that state. It means no code was offered, which is the overwhelmingly
 * common case and is emphatically not a refusal.
 */
final readonly class PromoVerdict
{
    private function __construct(
        public string $outcome,
        public ?Promo $promo,
        public int $discount,
        public string $reason,
        public ?string $message,
        /** The subtotal the discount was worked out against, after scope narrowing. */
        public int $discountable = 0,
    ) {}

    /** No code offered. The default, and not a problem. */
    public static function none(): self
    {
        return new self('none', null, 0, 'no_code', null);
    }

    public static function applied(Promo $promo, int $discount, int $discountable): self
    {
        return new self('applied', $promo, $discount, 'applied', null, $discountable);
    }

    /**
     * ⚠️ THE MESSAGE IS WRITTEN FOR THE CUSTOMER AND SHOWN TO THEM VERBATIM.
     *
     * `$reason` is for us — it is what a log line and a test assert on, and it never changes
     * when the wording does.
     */
    public static function refused(string $reason, string $message, ?Promo $promo = null): self
    {
        return new self('refused', $promo, 0, $reason, $message);
    }

    public function isApplied(): bool
    {
        return $this->outcome === 'applied';
    }

    public function isRefused(): bool
    {
        return $this->outcome === 'refused';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'message' => $this->message,
            'code' => $this->promo?->code,
            'description' => $this->promo?->description,
            'discount' => $this->discount,
            'discountable' => $this->discountable,
        ];
    }
}
