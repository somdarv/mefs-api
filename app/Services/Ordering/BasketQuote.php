<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderType;
use App\Models\CheckoutSession;
use App\Models\SystemSetting;
use App\Services\Promotions\PromoResolver;
use App\Services\Promotions\PromoVerdict;

/**
 * What the checkout screen needs to render a basket: priced lines, a quote for each way of
 * getting the food, and the gate's verdict with its reason.
 *
 * ⚠️ EVERY NUMBER HERE COMES FROM THE SAME SERVICES THE ORDER USES. `BasketPricer` for the
 * lines, `PriceCalculator` for the totals, `CycleGate` for the verdict. Nothing is
 * re-derived "just for display", because a display calculation that disagrees with the
 * charge is a calculation the customer will be right about.
 *
 * ⚠️ AND IT IS ADVISORY. A quote is a snapshot of a world that can change in the seconds
 * before confirm — someone else takes the last portion, she closes the day. The confirm
 * path re-runs the gate under a lock and is the only thing that decides. This is what the
 * customer is shown, not what they are promised.
 */
final class BasketQuote
{
    public function __construct(
        private readonly BasketPricer $pricer,
        private readonly PriceCalculator $prices,
        private readonly CycleGate $gate,
        private readonly PromoResolver $promos,
    ) {}

    /**
     * @return array{lines: list<array<string, mixed>>, quotes: list<array<string, mixed>>, ordering: array<string, mixed>|null}
     */
    public function for(CheckoutSession $session): array
    {
        $lines = BasketLine::listFrom($session->lines ?? []);

        if ($lines === []) {
            return ['lines' => [], 'quotes' => [], 'ordering' => null, 'promo' => PromoVerdict::none()->toArray()];
        }

        try {
            $priced = $this->pricer->price($lines);
        } catch (OrderRefused $refused) {
            // A basket holding something that has been retired. Say so rather than
            // pricing the rest and pretending the total is right.
            return [
                'lines' => [],
                'quotes' => [],
                'ordering' => [
                    'status' => 'closed',
                    'reason' => $refused->reason,
                    'message' => $refused->getMessage(),
                    'opens_at' => null,
                    'closes_at' => null,
                    'remaining' => null,
                ],
                'promo' => PromoVerdict::none()->toArray(),
            ];
        }

        /*
         * ⚠️ RESOLVED WITH NO PHONE NUMBER, AND THAT IS WHY THIS QUOTE IS ADVISORY.
         *
         * The basket has no contact details yet — they are entered on the confirm step — so
         * the two person-bound rules cannot be evaluated here: "first order only" and the
         * per-customer limit. A quote can therefore show a discount that confirm refuses,
         * and it says so through the same `reason` field rather than through a mismatched
         * total appearing at the last moment.
         *
         * Passing a phone we do not have would mean inventing one. The honest move is to
         * evaluate what is knowable now and re-evaluate everything at confirm, which is what
         * `OrderCreator` does.
         */
        $promo = $this->promos->resolve($session->promo_code, $priced);

        return [
            'lines' => array_map(fn (PricedLine $l) => [
                'menu_item_id' => $l->menuItemId,
                'menu_item_option_id' => $l->option->id,
                'name' => $l->name,
                'unit_price' => $l->unitPrice,
                'quantity' => $l->quantity,
                'line_total' => $l->unitPrice * $l->quantity,
                'size_label' => $l->sizeLabel,
                'variant_key' => $l->variantKey,
                'category' => $l->category->value,
                'notes' => $l->notes,
            ], $priced),

            'quotes' => $this->quotes($priced, $session, $promo->discount),
            'ordering' => $this->ordering($session),
            'promo' => $promo->toArray(),
        ];
    }

    /**
     * One quote per way of getting the food, each saying whether it is even possible.
     *
     * An unavailable option carries a REASON rather than being omitted. "Shipping is not
     * offered on this basket because it holds a cooked meal" is a sentence the checkout
     * screen can show; a missing radio button is a mystery.
     *
     * @param  list<PricedLine>  $priced
     * @return list<array<string, mixed>>
     */
    private function quotes(array $priced, CheckoutSession $session, int $discount): array
    {
        $pantryOnly = $this->pricer->isPantryOnly($priced);
        $hasDay = $session->cycle_day_id !== null;

        $quotes = [];

        foreach (OrderType::cases() as $type) {
            $unavailable = match (true) {
                $type === OrderType::Shipping && ! $pantryOnly => 'Cooked food has to be collected or delivered on a cooking day.',

                $type === OrderType::Shipping && SystemSetting::get('pantry_shipping_enabled', true) !== true => 'Shipping is switched off at the moment.',

                $type === OrderType::Delivery && SystemSetting::get('delivery_enabled', true) !== true => 'Delivery is switched off at the moment.',

                $type->requiresCycleDay() && ! $hasDay => 'Pick a day to collect or receive this order.',

                default => null,
            };

            /*
             * The same discount on every quote, because it is scoped to the food and the
             * food does not change between pickup and delivery. If it varied by order type
             * it would mean the discount was reaching the delivery fee, which is the one
             * thing it must never do (§5.3).
             */
            $totals = $this->prices->calculate($priced, $type, $discount);

            $quotes[] = array_merge(
                ['order_type' => $type->value, 'label' => $type->label(), 'available' => $unavailable === null],
                $unavailable === null ? [] : ['unavailable_reason' => $unavailable],
                $totals->toArray(),
            );
        }

        return $quotes;
    }

    /** The gate's verdict for the bound day, or null when the basket is not bound to one. */
    private function ordering(CheckoutSession $session): ?array
    {
        $day = $session->cycleDay;

        if ($day === null) {
            return null;
        }

        return $this->gate->check($day->cycle, $day)->toArray();
    }
}
