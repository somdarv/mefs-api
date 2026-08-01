<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ⚠️ FOR TESTS THAT NEED *AN* ORDER TO EXIST, NOT FOR TESTS ABOUT ORDERING.
 *
 * `OrderCreator` is the only path from a basket to an order, and it is the only path that
 * runs the cycle gate, the capacity ledger, the pricing and the audit row. Anything asserting
 * how orders come into being must go through it — that pairing is brief §5.8 and it is what
 * `OrderCreatorTest` exists to hold in place.
 *
 * This factory exists so a test about something *else* — a settlement file needing a payment,
 * which needs an order to hang off — does not have to build a cycle, a menu and a basket to
 * get there. It deliberately does not touch capacity, and it never will.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $branch = Branch::query()->first() ?? Branch::query()->create([
            'name' => 'Test kitchen',
            'slug' => 'test-kitchen',
            'address' => '1 Test Road',
            'phone' => '+233244000000',
            'is_active' => true,
        ]);

        $total = $this->faker->numberBetween(2, 40) * 500;

        return [
            'order_number' => 'T'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'tracking_token' => Str::random(40),

            'branch_id' => $branch->id,
            // Snapshot, never a live join — the same rule the real creator follows.
            'branch_snapshot' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ],

            'status' => OrderStatus::Received->value,

            /*
             * ⚠️ SHIPPING — A PANTRY ORDER — IS THE DEFAULT, AND IT HAS TO BE.
             *
             * `orders_fulfilment_binding_check` requires a pickup or delivery order to carry
             * both a `cycle_day_id` and a `fulfil_date`, because a meal floating free of a
             * cooking day is the bug that constraint exists to make impossible. A pantry
             * order is the one shape that legitimately stands alone, so it is the only
             * default this factory can offer without inventing a cycle.
             *
             * A test that needs a meal order should build it through `OrderCreator` — which
             * is where the cycle day comes from in the first place.
             */
            'order_type' => OrderType::Shipping->value,
            'delivery_address' => $this->faker->streetAddress(),

            'source' => OrderSource::Online->value,

            'subtotal' => $total,
            'total' => $total,

            'contact_name' => $this->faker->name(),
            'contact_phone' => '+2332440'.$this->faker->numberBetween(10000, 99999),

            'placed_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['is_paid' => true, 'payment_status' => 'completed']);
    }
}
