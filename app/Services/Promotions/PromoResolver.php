<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Models\Order;
use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Services\Ordering\PricedLine;
use App\Support\GhanaPhone;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️⚠️ THE ONLY THING THAT DECIDES WHETHER A CODE APPLIES, AND FOR HOW MUCH. ⚠️⚠️
 *
 * The same argument as `OrderCreator`, one layer down. The checkout screen quotes a
 * discount, and the order applies one; if those are two implementations they drift, and the
 * day they drift the customer is looking at ₵10 off and their bank says ₵0 off — and they
 * are right. Both call `resolve()`. There is no second copy.
 *
 * ── THE THREE RULES THAT MATTER ───────────────────────────────────────────────
 *
 * 1. ⚠️ **THE DISCOUNT NEVER TOUCHES THE DELIVERY FEE.** It applies to the discountable
 *    subtotal — the food — and nothing else. She uses a third-party courier, so that fee is
 *    money she collects and hands over (brief §5.3): discounting ₵15 of it does not cost
 *    her a smaller margin, it costs her ₵15 out of her own pocket, on every single delivery
 *    the code touches. This is the promo bug that actually loses money, and it is invisible
 *    on a receipt because the line still reads "delivery ₵15".
 *
 * 2. ⚠️ **THE QUOTE IS NEVER TRUSTED.** `resolve()` runs again inside `OrderCreator`'s
 *    transaction, against the basket as it is at that moment. A customer who applies a code
 *    at ₵60, goes back, removes two dishes and confirms at ₵20 gets what a ₵20 basket earns.
 *    A discount carried forward from a quote is a number the client chose.
 *
 * 3. ⚠️ **USAGE IS COUNTED UNDER A LOCK.** `redeem()` re-reads the promo `lockForUpdate`
 *    and re-checks the limits before writing. Without it, two people redeeming the last use
 *    of a one-shot code at the same instant both read `times_used = 0`, both pass, and the
 *    limit was never a limit. Exactly the shape `PortionLedger` exists to prevent.
 */
final class PromoResolver
{
    /**
     * Check a code against a basket.
     *
     * Pure: reads rows, writes nothing. Safe to call from a quote endpoint on every
     * keystroke, and called again for real at order time.
     *
     * @param  list<PricedLine>  $lines
     */
    public function resolve(?string $code, array $lines, ?string $phone = null, ?int $customerId = null): PromoVerdict
    {
        if ($code === null || trim($code) === '') {
            return PromoVerdict::none();
        }

        $promo = Promo::findByCode($code);

        /*
         * ⚠️ AN UNKNOWN CODE AND A DEACTIVATED ONE GET THE SAME MESSAGE ON PURPOSE.
         *
         * Distinguishing them turns the endpoint into an oracle: an attacker can enumerate
         * which codes exist and wait for one to be switched on. The `reason` differs, so we
         * can still tell them apart in a log — the customer just cannot.
         */
        if ($promo === null) {
            return PromoVerdict::refused('unknown_code', 'That code isn\'t valid.');
        }

        if (! $promo->is_active) {
            return PromoVerdict::refused('inactive', 'That code isn\'t valid.', $promo);
        }

        if (! $promo->hasStarted()) {
            return PromoVerdict::refused(
                'not_yet_started',
                'That code isn\'t live yet.',
                $promo,
            );
        }

        if ($promo->hasEnded()) {
            return PromoVerdict::refused('expired', 'That code has expired.', $promo);
        }

        if ($promo->isExhausted()) {
            return PromoVerdict::refused(
                'exhausted',
                'That code has been fully used.',
                $promo,
            );
        }

        // ── Against this basket ───────────────────────────────────────────────

        $discountable = $this->discountable($promo, $lines);

        /*
         * Scope produces its own refusal, separate from "spend more". A pantry code on a
         * basket of dinners is not a customer who needs to spend ₵20 more — it is a customer
         * who needs to be told the code is for something else, or they will add a dish and
         * try again.
         */
        if ($discountable === 0) {
            return PromoVerdict::refused(
                'scope_mismatch',
                $this->scopeMessage($promo),
                $promo,
            );
        }

        if ($promo->min_subtotal !== null && $discountable < $promo->min_subtotal) {
            return PromoVerdict::refused(
                'below_minimum',
                sprintf(
                    'Spend %s to use this code.',
                    $this->cedis($promo->min_subtotal),
                ),
                $promo,
            );
        }

        // ── Against this person ───────────────────────────────────────────────

        $normalisedPhone = $phone === null ? null : GhanaPhone::normalise($phone);

        if ($normalisedPhone !== null) {
            $refusal = $this->checkPerson($promo, $normalisedPhone);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $discount = $promo->discountOn($discountable);

        /*
         * A promo that computes to nothing is refused rather than applied at zero. "Applied:
         * ₵0.00 off" is a worse experience than a refusal with a reason, and it would put a
         * pointless redemption row against a limited code.
         */
        if ($discount === 0) {
            return PromoVerdict::refused(
                'no_effect',
                'That code doesn\'t take anything off this basket.',
                $promo,
            );
        }

        return PromoVerdict::applied($promo, $discount, $discountable);
    }

    /**
     * ⚠️ THE DISCOUNTABLE SUBTOTAL: THE FOOD, NARROWED BY SCOPE. NOTHING ELSE.
     *
     * No delivery fee — see rule 1 at the top of this class. No service charge either: a
     * percentage taken off a charge that is itself a percentage of the subtotal is a
     * compounding number nobody can explain on a receipt.
     *
     * @param  list<PricedLine>  $lines
     */
    private function discountable(Promo $promo, array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            if ($promo->scope->covers($line->category)) {
                $total += $line->unitPrice * $line->quantity;
            }
        }

        return $total;
    }

    /**
     * Limits that depend on who is ordering.
     *
     * ⚠️ COUNTED ON THE PHONE, NOT ON `customer_id`. Most orders are from guests with no
     * customer row at all, and a per-customer limit counted on a null id is not a limit.
     * The phone is the identity this business actually uses.
     */
    private function checkPerson(Promo $promo, string $phone): ?PromoVerdict
    {
        if ($promo->first_order_only && $this->hasOrderedBefore($phone)) {
            return PromoVerdict::refused(
                'not_first_order',
                'That code is for a first order.',
                $promo,
            );
        }

        if ($promo->usage_limit_per_customer !== null) {
            $used = PromoRedemption::query()
                ->where('promo_id', $promo->id)
                ->where('phone', $phone)
                ->count();

            if ($used >= $promo->usage_limit_per_customer) {
                return PromoVerdict::refused(
                    'already_used',
                    $promo->usage_limit_per_customer === 1
                        ? 'You\'ve already used that code.'
                        : 'You\'ve used that code as many times as it allows.',
                    $promo,
                );
            }
        }

        return null;
    }

    /**
     * Has this number ordered before?
     *
     * ⚠️ CANCELLED ORDERS COUNT. Otherwise "first order only" is defeated by ordering,
     * cancelling, and ordering again — and a code worth 30% is worth the two minutes that
     * takes.
     */
    private function hasOrderedBefore(string $phone): bool
    {
        return Order::query()->where('contact_phone', $phone)->exists();
    }

    /**
     * ⚠️ TAKE THE LOCK AND CONFIRM THE DISCOUNT. CALL FROM INSIDE THE ORDER'S TRANSACTION,
     * **BEFORE** THE TOTALS ARE COMPUTED.
     *
     * The ordering here is the whole point and it is easy to get backwards. The redemption
     * row cannot be written until the order exists, because it carries `order_id` — so the
     * obvious shape is save-then-redeem. But that writes a `discount` onto the order and
     * *then* asks whether the promo still had a use left, and when the answer is no the
     * order has already been saved at a price nobody was entitled to.
     *
     * So this splits in two. `confirm()` re-reads the promo `lockForUpdate` and re-checks
     * the two limits a counter can race on, returning what may actually be granted. The lock
     * is held for the rest of the transaction, so nothing can take the last use between here
     * and `record()`. The totals are computed from *this* figure.
     *
     * Returns **zero** when the last use went to somebody else in the meantime. That is not
     * an error: the order is still perfectly good at full price, and killing a confirmed sale
     * at the final step over a promo is a much worse outcome than a customer paying what
     * their basket is worth.
     *
     * Everything else — the window, the scope, the minimum — was checked against a basket
     * that has not changed since, inside this same transaction.
     */
    public function confirm(PromoVerdict $verdict, string $phone): int
    {
        if (! $verdict->isApplied() || $verdict->promo === null) {
            return 0;
        }

        $promo = Promo::query()->lockForUpdate()->find($verdict->promo->id);

        if ($promo === null || $promo->isExhausted()) {
            return 0;
        }

        if ($promo->usage_limit_per_customer !== null) {
            $used = PromoRedemption::query()
                ->where('promo_id', $promo->id)
                ->where('phone', $phone)
                ->count();

            if ($used >= $promo->usage_limit_per_customer) {
                return 0;
            }
        }

        return $verdict->discount;
    }

    /**
     * Write the evidence. Same transaction, after the order exists, and only ever with the
     * figure `confirm()` returned — never with `$verdict->discount`.
     *
     * A zero discount writes nothing at all: no redemption row and no increment. A promo
     * that took nothing off was not used, and counting it would burn a use of a one-shot
     * code on an order that got no benefit from it.
     */
    public function record(PromoVerdict $verdict, Order $order, int $discount): void
    {
        if ($discount <= 0 || $verdict->promo === null) {
            return;
        }

        PromoRedemption::query()->create([
            'promo_id' => $verdict->promo->id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'phone' => $order->contact_phone,
            'discount' => $discount,
        ]);

        // Atomic increment rather than `$promo->times_used + 1`: the lock makes either
        // correct here, and this one stays correct if the lock is ever lost in a refactor.
        DB::table('promos')->where('id', $verdict->promo->id)->increment('times_used');
    }

    private function scopeMessage(Promo $promo): string
    {
        return match ($promo->scope->value) {
            'meals' => 'That code is for cooked dishes.',
            'pantry' => 'That code is for the pantry range.',
            default => 'That code doesn\'t apply to anything in your basket.',
        };
    }

    private function cedis(int $pesewas): string
    {
        return '₵'.number_format($pesewas / 100, 2);
    }
}
