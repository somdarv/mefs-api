<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Enums\CycleStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderCycle;
use App\Models\User;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderStatusMachine;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clock that gives slots back (departure #6).
 *
 * ⚠️ THE TEST THIS FILE EXISTS FOR is `an expired hold frees the portions it was holding`.
 * Until the scheduled command landed, `slot_hold_expires_at` was written on every order and
 * read by nothing — a column that looked like a feature. Everything here asserts that the
 * clock now actually moves capacity.
 */
final class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    private CycleDay $day;

    private MenuOption $waakye;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));

        $this->cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);
        $this->cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $this->cycle->days()->orderBy('date')->firstOrFail();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
            ['is_available' => true, 'portion_capacity' => 10],
        );

        $this->waakye = $item->options()->firstOrFail();
    }

    private function order(OrderSource $source = OrderSource::Online, int $quantity = 4): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, $quantity)],
            type: OrderType::Pickup,
            source: $source,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $this->day->id,
            actor: $source === OrderSource::Online ? null : $this->staff(),
        ));
    }

    private function staff(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    private function portionsSold(): int
    {
        return (int) CycleDayItem::query()
            ->where('cycle_day_id', $this->day->id)
            ->where('menu_item_id', $this->waakye->menu_item_id)
            ->value('portions_sold');
    }

    private function release(): void
    {
        $this->artisan('orders:release-expired-holds')->assertSuccessful();
    }

    // ── The point of the whole mechanism ──────────────────────────────────────

    public function test_an_expired_hold_frees_the_portions_it_was_holding(): void
    {
        $order = $this->order();

        $this->assertSame(4, $this->portionsSold());

        // Past the 30-minute customer payment window.
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->release();

        $this->assertSame(0, $this->portionsSold(), 'The pot never got its portions back.');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertTrue($order->fresh()->hold_expired);
    }

    public function test_a_released_slot_can_be_sold_again(): void
    {
        $this->day->update(['capacity' => 1]);

        $this->order();
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->release();

        // The day had room for exactly one order, and the abandoned one gave it back.
        $second = $this->order();

        $this->assertSame(OrderStatus::Received, $second->status);
    }

    public function test_the_release_is_recorded_with_its_reason(): void
    {
        $order = $this->order();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->release();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'received',
            'to_status' => 'cancelled',
            'note' => 'Slot hold expired before payment',
        ]);

        // No actor: the clock did this, and pinning it on whoever last logged in would be a
        // lie in the one record that has to be trustworthy.
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => 'cancelled',
            'actor_id' => null,
        ]);
    }

    // ── What it must leave alone ──────────────────────────────────────────────

    public function test_a_hold_that_has_not_run_out_is_left_alone(): void
    {
        $order = $this->order();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:29:00Z'));
        $this->release();

        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertSame(4, $this->portionsSold());
    }

    public function test_a_paid_order_is_never_released(): void
    {
        $order = $this->order();
        $order->forceFill(['is_paid' => true, 'payment_status' => 'completed'])->save();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T14:00:00Z'));
        $this->release();

        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertSame(4, $this->portionsSold());
    }

    /**
     * ⚠️ Accepting is a commitment.
     *
     * An automated job that cancels food she is already planning to cook is wrong in the one
     * direction that cannot be undone — the customer turns up and there is nothing. An
     * accepted order that never pays is a phone call, not a cron job.
     */
    public function test_an_accepted_order_is_never_released_however_long_it_goes_unpaid(): void
    {
        $order = $this->order(OrderSource::Phone);

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Accepted, $this->staff());

        // Days past a two-hour hold.
        $this->travelTo(CarbonImmutable::parse('2026-08-05T09:00:00Z'));
        $this->release();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertFalse($order->fresh()->hold_expired);
        $this->assertSame(4, $this->portionsSold());
    }

    public function test_her_manual_orders_get_the_longer_hold(): void
    {
        $manual = $this->order(OrderSource::Phone, 1);
        $online = $this->order(OrderSource::Online, 1);

        // 45 minutes: past the customer's 30-minute window, well inside her two hours.
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:45:00Z'));
        $this->release();

        $this->assertSame(OrderStatus::Cancelled, $online->fresh()->status);
        $this->assertSame(OrderStatus::Received, $manual->fresh()->status);
    }

    // ── Operating it ──────────────────────────────────────────────────────────

    public function test_a_dry_run_changes_nothing(): void
    {
        $order = $this->order();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->artisan('orders:release-expired-holds --dry-run')->assertSuccessful();

        $this->assertSame(OrderStatus::Received, $order->fresh()->status);
        $this->assertSame(4, $this->portionsSold());
    }

    public function test_running_it_twice_releases_nothing_the_second_time(): void
    {
        $this->order();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->release();
        $this->release();

        // One cancellation, one release. A second pass double-releasing would drive
        // portions_sold below what is actually sold and quietly oversell the day.
        $this->assertSame(0, $this->portionsSold());
        $this->assertSame(1, Order::query()->where('status', 'cancelled')->count());
    }

    public function test_the_command_is_scheduled(): void
    {
        // ⚠️ The mechanism was complete and inert for a whole milestone because nothing
        // registered it. Asserting the schedule is what stops that recurring.
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'orders:release-expired-holds'));

        $this->assertCount(1, $events, 'The hold-expiry command is not on the schedule.');
    }
}
