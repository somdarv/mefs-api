<?php

declare(strict_types=1);

namespace App\Services\Ordering;

/**
 * What an order costs, in integer pesewas. 4000 is GHS 40.00.
 *
 * The only place that ever divides by 100 is the formatter, on the client, for display.
 * Nothing on this side sees a float or a decimal string — a money value that has been
 * through a float has already lost, and it loses quietly.
 */
final readonly class OrderTotals
{
    public function __construct(
        public int $subtotal,
        public int $serviceCharge,
        public int $deliveryFee,
        public int $discount,
        public int $total,
        /**
         * `third_party` means the delivery fee is PASS-THROUGH: collected and handed to the
         * courier. It is not revenue, and every analytics query has to exclude it (brief
         * §5.3). Carried here so the value stored on the order is the one that was in force
         * when the money was calculated, not whatever the setting says next month.
         */
        public string $deliveryFeeCollection,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'service_charge' => $this->serviceCharge,
            'delivery_fee' => $this->deliveryFee,
            'discount' => $this->discount,
            'total' => $this->total,
            'delivery_fee_collection' => $this->deliveryFeeCollection,
        ];
    }
}
