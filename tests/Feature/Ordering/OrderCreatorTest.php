<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Enums\CycleOverride;
use App\Enums\CycleStatus;
use App\Enums\MenuCategory;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderCycle;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderRefused;
use App\Services\Ordering\OrderStatusMachine;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE ONE-PATH TEST (brief §5.8, trap §10.9).
 *
 * The point of this file is the pairs. Every refusal is asserted **twice** — once through
 * the customer path and once through admin manual entry — because the original's mistake
 * was not a missing gate, it was a gate on the route nobody used. A test that only drives
 * the customer path would have passed on the original too, right up to the sale of 23
 * portions against a balance of 6.
 *
 * Scenario throughout is hers: taking orders 1-4 August, cooking 5-12 August.
 */
final class OrderCreatorTest extends TestCase
{
    use RefreshDatabase;

    private OrderCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->creator = app(OrderCreator::class);

        // Inside the ordering window, three days before the first cooking day.
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function cycle(array $overrides = []): OrderCycle
    {
        $cycle = app(CycleBuilder::class)->create(array_merge([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ], $overrides));

        $cycle->update(['status' => CycleStatus::Published->value]);

        return $cycle->fresh(['days.items']);
    }

    private function dayOf(OrderCycle $cycle, string $date = '2026-08-05'): CycleDay
    {
        $day = $cycle->days->first(fn (CycleDay $d) => $d->date->toDateString() === $date);

        $this->assertNotNull($day, "The cycle has no day for {$date}.");

        return $day;
    }

    /** Put a dish on a day, optionally capped, and hand back its cheapest option. */
    private function serve(CycleDay $day, string $slug, ?int $capacity = null): MenuOption
    {
        $item = MenuItem::query()->where('slug', $slug)->firstOrFail();

        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $day->id, 'menu_item_id' => $item->id],
            ['is_available' => true, 'portion_capacity' => $capacity],
        );

        return $item->options()->orderBy('price')->firstOrFail();
    }

    private function option(string $slug, ?string $optionKey = null): MenuOption
    {
        $item = MenuItem::query()->where('slug', $slug)->firstOrFail();

        return $optionKey === null
            ? $item->options()->orderBy('price')->firstOrFail()
            : $item->options()->where('option_key', $optionKey)->firstOrFail();
    }

    /**
     * A basket, ready to become an order.
     *
     * `$actor` is what makes it manual entry. Everything else about the two paths is
     * identical, and that identity is the thing under test.
     */
    private function draft(
        array $lines,
        ?CycleDay $day,
        OrderType $type = OrderType::Pickup,
        ?User $actor = null,
        array $overrides = [],
    ): OrderDraft {
        return new OrderDraft(
            lines: $lines,
            type: $type,
            source: $actor === null ? OrderSource::Online : OrderSource::Phone,
            contactName: $overrides['name'] ?? 'Ama Serwaa',
            contactPhone: $overrides['phone'] ?? '024 123 4567',
            cycleDayId: $day?->id,
            deliveryAddress: $overrides['address'] ?? null,
            actor: $actor,
        );
    }

    private function line(MenuOption $option, int $quantity = 1): BasketLine
    {
        return new BasketLine($option->id, $quantity);
    }

    private function staff(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    // ── The happy path ────────────────────────────────────────────────────────

    public function test_a_customer_order_is_created_with_a_number_a_token_and_an_audit_row(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $order = $this->creator->create($this->draft([$this->line($waakye, 2)], $day));

        $this->assertSame('A001', $order->order_number);

        // The token is what URLs use, and it is long and random precisely so nobody can
        // walk it and read off the day's volume (brief §5.6).
        $this->assertSame(48, strlen($order->tracking_token));
        $this->assertNotSame((string) $order->id, $order->tracking_token);

        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame(OrderType::Pickup, $order->order_type);
        $this->assertSame(OrderSource::Online, $order->source);
        $this->assertFalse($order->is_manual_entry);
        $this->assertFalse($order->is_paid);

        $this->assertSame('2026-08-05', $order->fulfil_date->toDateString());
        $this->assertSame($day->id, $order->cycle_day_id);
        $this->assertSame($cycle->id, $order->order_cycle_id);

        // E.164, always. "024 123 4567" and "+233 24 123 4567" are one customer.
        $this->assertSame('+233241234567', $order->contact_phone);

        // The branch is a snapshot, not a join.
        $this->assertSame("Mef's Kitchen", $order->branch_snapshot['name']);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'received',
        ]);
    }

    public function test_order_numbers_run_in_sequence_per_branch(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $first = $this->creator->create($this->draft([$this->line($waakye)], $day));
        $second = $this->creator->create($this->draft([$this->line($waakye)], $day));
        $third = $this->creator->create($this->draft([$this->line($waakye)], $day));

        $this->assertSame(['A001', 'A002', 'A003'], [
            $first->order_number, $second->order_number, $third->order_number,
        ]);
    }

    // ── Money ─────────────────────────────────────────────────────────────────

    public function test_money_comes_from_the_catalogue_not_the_request(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $etor = $this->serve($day, 'plantain-etor');   // cheapest option: plain, 4000

        // Two plain Etor at 4000. There is nowhere on a BasketLine to say otherwise —
        // that is the assertion. A client that could send a price could send `1`.
        $order = $this->creator->create($this->draft([$this->line($etor, 2)], $day));

        $this->assertSame(8000, $order->subtotal);
        $this->assertSame(0, $order->service_charge, 'Off at launch.');
        $this->assertSame(0, $order->delivery_fee, 'Pickup carries no fee.');
        $this->assertSame(8000, $order->total);

        $this->assertSame(4000, $order->items->first()->unit_price);
        $this->assertSame('Plantain Etor — Plain', $order->items->first()->name);
        // Load-bearing for v2 recipes (brief §12.2).
        $this->assertSame($etor->id, $order->items->first()->menu_item_option_id);
    }

    public function test_the_service_charge_applies_only_when_enabled_and_respects_its_cap(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');       // 4000

        SystemSetting::put('service_charge_percent', 10);
        SystemSetting::put('service_charge_cap', 100000);

        // Still off. A percentage with the feature disabled must charge nothing — the
        // original's typed-settings bug was exactly this shape.
        $off = $this->creator->create($this->draft([$this->line($waakye, 3)], $day));
        $this->assertSame(0, $off->service_charge);
        $this->assertSame(12000, $off->total);

        SystemSetting::put('service_charge_enabled', true);

        $on = $this->creator->create($this->draft([$this->line($waakye, 3)], $day));
        $this->assertSame(1200, $on->service_charge);
        $this->assertSame(13200, $on->total);

        SystemSetting::put('service_charge_cap', 500);

        $capped = $this->creator->create($this->draft([$this->line($waakye, 3)], $day));
        $this->assertSame(500, $capped->service_charge);
    }

    public function test_the_delivery_fee_is_recorded_as_pass_through_and_excluded_from_revenue(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $order = $this->creator->create($this->draft(
            [$this->line($waakye)],
            $day,
            OrderType::Delivery,
            overrides: ['address' => '12 Ring Road, Accra'],
        ));

        $this->assertSame(2000, $order->delivery_fee);
        $this->assertSame(6000, $order->total);
        $this->assertSame('third_party', $order->delivery_fee_collection);

        // Counting a courier's fee as income overstates every revenue figure (brief §5.3).
        $this->assertSame(4000, $order->revenueTotal());
    }

    // ── The gate, from BOTH paths ─────────────────────────────────────────────

    public function test_both_paths_are_refused_after_the_cutoff(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $this->travelTo(CarbonImmutable::parse('2026-08-04T18:00:01Z'));

        foreach ([null, $this->staff()] as $actor) {
            try {
                $this->creator->create($this->draft([$this->line($waakye)], $day, actor: $actor));
                $this->fail('The gate let an order through after the cutoff.');
            } catch (OrderRefused $refused) {
                $this->assertSame('cutoff_passed', $refused->reason);
            }
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_both_paths_are_refused_when_the_day_is_closed(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $day->update(['is_open' => false]);

        foreach ([null, $this->staff()] as $actor) {
            try {
                $this->creator->create($this->draft([$this->line($waakye)], $day->fresh(), actor: $actor));
                $this->fail('The gate let an order through on a closed day.');
            } catch (OrderRefused $refused) {
                $this->assertSame('day_closed', $refused->reason);
            }
        }
    }

    public function test_both_paths_are_refused_when_the_dish_is_sold_out(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye', capacity: 2);

        $this->creator->create($this->draft([$this->line($waakye, 2)], $day));

        foreach ([null, $this->staff()] as $actor) {
            try {
                $this->creator->create($this->draft([$this->line($waakye)], $day, actor: $actor));
                $this->fail('The gate sold a portion that did not exist.');
            } catch (OrderRefused $refused) {
                $this->assertSame('item_capacity', $refused->reason);
                $this->assertSame('sold_out', $refused->state?->status->value);
            }
        }
    }

    /**
     * ⚠️ The whole reopen feature, asserted from the admin path.
     *
     * She does NOT get a per-order bypass. What she gets is `force_open` on the cycle,
     * which is permissioned, logged and has a reason attached — and which then applies to
     * customers too, because "we are open" is not a per-order fact.
     */
    public function test_force_open_reopens_the_week_for_both_paths(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $this->travelTo(CarbonImmutable::parse('2026-08-04T21:00:00Z'));

        $cycle->applyOverride(CycleOverride::ForceOpen, 'Rang back a regular', $this->staff());

        $manual = $this->creator->create($this->draft([$this->line($waakye)], $day->fresh(), actor: $this->staff()));
        $online = $this->creator->create($this->draft([$this->line($waakye)], $day->fresh()));

        $this->assertSame(OrderStatus::Received, $manual->status);
        $this->assertSame(OrderStatus::Received, $online->status);
    }

    // ── Capacity arithmetic ───────────────────────────────────────────────────

    /**
     * ⚠️ SUMMED PER DISH, NOT CHECKED PER LINE.
     *
     * A standard Etor and a plain Etor come out of one pot. Checked line by line, both pass
     * against the same six remaining portions and the day oversells by an amount nobody
     * notices until service.
     */
    public function test_two_options_of_one_dish_are_counted_against_the_same_portions(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $this->serve($day, 'plantain-etor', capacity: 3);

        $plain = $this->option('plantain-etor', 'plain');
        $platter = $this->option('plantain-etor', 'standard');

        try {
            $this->creator->create($this->draft(
                [$this->line($plain, 2), $this->line($platter, 2)],
                $day,
            ));
            $this->fail('Four portions were sold out of a pot of three.');
        } catch (OrderRefused $refused) {
            $this->assertSame('item_capacity', $refused->reason);
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_placing_an_order_moves_the_portion_ledger(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye', capacity: 10);

        $this->creator->create($this->draft([$this->line($waakye, 4)], $day));

        $this->assertSame(4, (int) CycleDayItem::query()
            ->where('cycle_day_id', $day->id)
            ->where('menu_item_id', $waakye->menu_item_id)
            ->value('portions_sold'));
    }

    public function test_cancelling_gives_the_portions_back(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye', capacity: 4);

        $order = $this->creator->create($this->draft([$this->line($waakye, 4)], $day));

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertSame(0, (int) CycleDayItem::query()
            ->where('cycle_day_id', $day->id)
            ->where('menu_item_id', $waakye->menu_item_id)
            ->value('portions_sold'));

        // And the day can be sold again.
        $again = $this->creator->create($this->draft([$this->line($waakye, 4)], $day->fresh()));
        $this->assertSame(OrderStatus::Received, $again->status);
    }

    public function test_a_cancelled_order_stops_counting_against_the_day_capacity(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $day->update(['capacity' => 1]);

        $first = $this->creator->create($this->draft([$this->line($waakye)], $day->fresh()));

        try {
            $this->creator->create($this->draft([$this->line($waakye)], $day->fresh()));
            $this->fail('The day took a second order against a capacity of one.');
        } catch (OrderRefused $refused) {
            $this->assertSame('day_capacity', $refused->reason);
        }

        app(OrderStatusMachine::class)->transition($first, OrderStatus::Cancelled, $this->staff());

        $third = $this->creator->create($this->draft([$this->line($waakye)], $day->fresh()));
        $this->assertSame(OrderStatus::Received, $third->status);
    }

    // ── Fulfilment binding ────────────────────────────────────────────────────

    public function test_a_pantry_only_order_ships_with_no_cooking_day(): void
    {
        $base = $this->option('jollof-base', '1l-mild');   // 11000

        $order = $this->creator->create(new OrderDraft(
            lines: [$this->line($base, 2)],
            type: OrderType::Shipping,
            source: OrderSource::Online,
            contactName: 'Kojo Mensah',
            contactPhone: '0201234567',
            deliveryAddress: 'Tamale',
        ));

        $this->assertNull($order->fulfil_date);
        $this->assertNull($order->cycle_day_id);
        $this->assertNull($order->order_cycle_id);
        $this->assertSame(22000, $order->subtotal);
        $this->assertSame(3000, $order->delivery_fee, 'Nationwide shipping.');
    }

    public function test_a_meal_cannot_be_shipped(): void
    {
        $waakye = $this->option('waakye');

        $this->expectException(OrderRefused::class);

        $this->creator->create(new OrderDraft(
            lines: [$this->line($waakye)],
            type: OrderType::Shipping,
            source: OrderSource::Online,
            contactName: 'Kojo Mensah',
            contactPhone: '0201234567',
            deliveryAddress: 'Tamale',
        ));
    }

    public function test_a_meal_order_with_no_day_is_refused(): void
    {
        $waakye = $this->option('waakye');

        try {
            $this->creator->create($this->draft([$this->line($waakye)], null));
            $this->fail('A meal order was created floating free of a cooking day.');
        } catch (OrderRefused $refused) {
            $this->assertSame('fulfilment_mismatch', $refused->reason);
        }
    }

    /**
     * Pantry goods can ride along on a cooking day — "collect my jollof base on Wednesday"
     * is an ordinary request, and it must not be gated against a dish matrix it has no row
     * in. `checkItem` would call a missing row `item_not_on_menu`, which is right for a meal
     * and wrong for a jar.
     */
    public function test_pantry_goods_may_be_collected_on_a_cooking_day_without_a_matrix_row(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');
        $base = $this->option('jollof-base', '1l-mild');

        $order = $this->creator->create($this->draft(
            [$this->line($waakye), $this->line($base)],
            $day,
        ));

        $this->assertSame(15000, $order->subtotal);
        $this->assertCount(2, $order->items);
        $this->assertSame('2026-08-05', $order->fulfil_date->toDateString());
    }

    // ── Refusals that are not about the calendar ──────────────────────────────

    public function test_an_empty_basket_is_refused(): void
    {
        $cycle = $this->cycle();

        try {
            $this->creator->create($this->draft([], $this->dayOf($cycle)));
            $this->fail('An empty basket became an order.');
        } catch (OrderRefused $refused) {
            $this->assertSame('empty_basket', $refused->reason);
        }
    }

    public function test_a_retired_option_is_refused_rather_than_guessed_at(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $waakye->update(['is_active' => false]);

        try {
            $this->creator->create($this->draft([$this->line($waakye)], $day));
            $this->fail('An order line was created against an option that is not on sale.');
        } catch (OrderRefused $refused) {
            $this->assertSame('unknown_option', $refused->reason);
            $this->assertSame($waakye->id, $refused->menuItemOptionId);
        }
    }

    public function test_a_bad_phone_number_is_refused(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        try {
            $this->creator->create($this->draft([$this->line($waakye)], $day, overrides: ['phone' => '12345']));
            $this->fail('An unreachable order was accepted.');
        } catch (OrderRefused $refused) {
            $this->assertSame('invalid_contact', $refused->reason);
        }
    }

    public function test_a_delivery_needs_an_address(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        try {
            $this->creator->create($this->draft([$this->line($waakye)], $day, OrderType::Delivery));
            $this->fail('A delivery was accepted with nowhere to deliver it.');
        } catch (OrderRefused $refused) {
            $this->assertSame('invalid_contact', $refused->reason);
        }
    }

    // ── Departure #6: who may hold a slot unpaid ──────────────────────────────

    public function test_a_manual_order_holds_its_slot_longer_than_a_customer_order(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');

        $online = $this->creator->create($this->draft([$this->line($waakye)], $day));
        $manual = $this->creator->create($this->draft([$this->line($waakye)], $day, actor: $this->staff()));

        // 30 minutes: a payment window, not a hold. Leave Paystack without paying and the
        // seat comes back.
        $this->assertSame(30, (int) now()->diffInMinutes($online->slot_hold_expires_at));

        // 2 hours: a deliberate hold, because her regulars pay by MoMo after the call.
        $this->assertSame(120, (int) now()->diffInMinutes($manual->slot_hold_expires_at));

        $this->assertTrue($manual->is_manual_entry);
        $this->assertSame(OrderSource::Phone, $manual->source);
        $this->assertSame($this->staff()->id, $manual->created_by);

        // Neither is paid, and neither pretends to be.
        $this->assertFalse($online->is_paid);
        $this->assertFalse($manual->is_paid);
    }

    public function test_only_meal_lines_are_marked_date_bound_on_the_receipt(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle);
        $waakye = $this->serve($day, 'waakye');
        $base = $this->option('jollof-base', '500ml-mild');

        $order = $this->creator->create($this->draft(
            [$this->line($waakye), $this->line($base)],
            $day,
        ));

        $categories = $order->items->pluck('category', 'menu_item_option_id');

        $this->assertSame(MenuCategory::Meal->value, $categories[$waakye->id]);
        $this->assertSame(MenuCategory::Pantry->value, $categories[$base->id]);
    }
}
