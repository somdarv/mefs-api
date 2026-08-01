<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderType;
use App\Models\SystemSetting;

/**
 * ⚠️ THE ONLY PLACE MONEY IS COMPUTED.
 *
 * The client shows a total so the customer knows what they are agreeing to; it is display
 * only and is never sent. Both the checkout preview and the order itself come through here,
 * so the number the customer sees and the number they are charged cannot drift — they are
 * the same function of the same inputs.
 *
 * Everything is integer pesewas. There is one rounding decision in the whole file, on the
 * service charge, and it rounds half up.
 */
final class PriceCalculator
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  int  $discount  already resolved by `PromoResolver`, in pesewas
     */
    public function calculate(array $lines, OrderType $type, int $discount = 0): OrderTotals
    {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line->unitPrice * $line->quantity;
        }

        $serviceCharge = $this->serviceCharge($subtotal);
        $deliveryFee = $this->deliveryFee($type);

        /*
         * ⚠️ THE DISCOUNT ARRIVES ALREADY DECIDED, AND IS CLAMPED TO THE SUBTOTAL HERE.
         *
         * `PromoResolver` owns whether a code applies and for how much — this class owns
         * arithmetic and nothing else. The clamp is a second belt on the resolver's own cap:
         * every money column on `orders` is `unsignedInteger`, so a discount larger than the
         * subtotal does not produce a negative total, it produces a **database error at
         * insert on a sale that was otherwise fine**.
         *
         * Note what it is clamped against: the SUBTOTAL, never the total. Clamping against
         * the total would permit a discount that eats into the delivery fee, and that fee is
         * the courier's money, not hers (§5.3).
         */
        $discount = max(0, min($discount, $subtotal));

        return new OrderTotals(
            subtotal: $subtotal,
            serviceCharge: $serviceCharge,
            deliveryFee: $deliveryFee,
            discount: $discount,
            total: $subtotal + $serviceCharge + $deliveryFee - $discount,
            deliveryFeeCollection: $this->feeCollection($type),
        );
    }

    /**
     * A SERVICE CHARGE, never a tax.
     *
     * The original started with "tax 2.5%" and had to migrate away from it: calling this a
     * tax is a compliance statement, and not one to make by accident (brief §5.2, trap
     * §10.13). Off at launch, and the `enabled` flag is checked before the percentage — a
     * percentage left at 5 with the feature off must charge nothing.
     */
    private function serviceCharge(int $subtotal): int
    {
        if (SystemSetting::get('service_charge_enabled', false) !== true) {
            return 0;
        }

        $percent = (int) SystemSetting::get('service_charge_percent', 0);

        if ($percent <= 0) {
            return 0;
        }

        $charge = (int) round($subtotal * $percent / 100);
        $cap = SystemSetting::get('service_charge_cap');

        return $cap === null ? $charge : min($charge, (int) $cap);
    }

    /** Pickup carries nothing. Delivery and shipping read different settings. */
    private function deliveryFee(OrderType $type): int
    {
        $key = $type->feeSettingKey();

        if ($key === null) {
            return 0;
        }

        return (int) SystemSetting::get($key, 0);
    }

    /**
     * Who keeps the fee.
     *
     * Pickup has no fee, so the question does not arise — but the column is NOT NULL, and
     * `third_party` is the safe default to store: it excludes a zero from revenue rather
     * than adding one to it.
     */
    private function feeCollection(OrderType $type): string
    {
        if ($type === OrderType::Pickup) {
            return 'third_party';
        }

        $value = SystemSetting::get('delivery_fee_collection', 'third_party');

        return in_array($value, ['self', 'third_party'], true) ? $value : 'third_party';
    }
}
