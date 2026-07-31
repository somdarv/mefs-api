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
use App\Services\Ordering\IllegalTransition;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderStatusMachine;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The status machine is the policy; the client's map is decoration.
 *
 * Every test here would still pass if ../mefs/src/types/order.ts were deleted, and that is
 * the point — the original enforced its transitions in the UI, so any client that skipped
 * the UI skipped the rules (brief §5.1).
 */
final class OrderStatusMachineTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatusMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->machine = app(OrderStatusMachine::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * One cycle for the whole test, reused.
     *
     * Building a second identical one is refused by `order_cycles_no_overlapping_service_window`
     * — an EXCLUSION constraint, not application validation, precisely so two admins saving
     * at once cannot both pass a select-then-insert check.
     */
    private function cycle(): OrderCycle
    {
        $existing = OrderCycle::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        $cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);

        $cycle->update(['status' => CycleStatus::Published->value]);

        return $cycle->fresh();
    }

    private function order(OrderType $type = OrderType::Pickup): Order
    {
        /** @var CycleDay $day */
        $day = $this->cycle()->days()->first();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $day->id, 'menu_item_id' => $item->id],
            ['is_available' => true],
        );

        /** @var MenuOption $option */
        $option = $item->options()->firstOrFail();

        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($option->id, 1)],
            type: $type,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $day->id,
            deliveryAddress: $type === OrderType::Pickup ? null : '12 Ring Road, Accra',
        ));
    }

    private function staff(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    // ── The ordinary run ──────────────────────────────────────────────────────

    public function test_an_order_walks_the_pickup_route(): void
    {
        $order = $this->order();

        foreach ([OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::ReadyForPickup, OrderStatus::Completed] as $next) {
            $this->machine->transition($order, $next, $this->staff());
        }

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);

        // The first row is the placement, then one per move.
        $this->assertSame(6, $order->statusHistory()->count());
    }

    public function test_the_transition_writes_the_timestamps_not_the_caller(): void
    {
        $order = $this->order();

        $this->assertNull($order->accepted_at);

        $this->travelTo(CarbonImmutable::parse('2026-08-05T07:30:00Z'));
        $this->machine->transition($order, OrderStatus::Accepted, $this->staff());

        $this->assertSame('2026-08-05T07:30:00+00:00', $order->fresh()->accepted_at->toIso8601String());

        $this->travelTo(CarbonImmutable::parse('2026-08-05T09:00:00Z'));
        $this->machine->transition($order, OrderStatus::Preparing, $this->staff());

        $this->assertSame('2026-08-05T09:00:00+00:00', $order->fresh()->started_at->toIso8601String());
        // Not rewritten by the later move.
        $this->assertSame('2026-08-05T07:30:00+00:00', $order->fresh()->accepted_at->toIso8601String());
    }

    public function test_every_change_leaves_an_audit_row_naming_who_did_it(): void
    {
        $order = $this->order();

        $this->machine->transition($order, OrderStatus::Accepted, $this->staff(), 'Confirmed by phone');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'received',
            'to_status' => 'accepted',
            'actor_id' => $this->staff()->id,
            'actor_name' => 'Mef',
            'note' => 'Confirmed by phone',
        ]);
    }

    // ── Refusals ──────────────────────────────────────────────────────────────

    public function test_a_skipped_step_is_refused(): void
    {
        $order = $this->order();

        $this->expectException(IllegalTransition::class);

        $this->machine->transition($order, OrderStatus::Completed, $this->staff());
    }

    public function test_a_terminal_order_cannot_move_again(): void
    {
        $order = $this->order();

        $this->machine->transition($order, OrderStatus::Cancelled, $this->staff());

        try {
            $this->machine->transition($order, OrderStatus::Accepted, $this->staff());
            $this->fail('A cancelled order was accepted.');
        } catch (IllegalTransition $refused) {
            $this->assertSame(OrderStatus::Cancelled, $refused->from);
        }
    }

    /**
     * A pickup order can never be out for delivery, and a delivery is never ready for
     * pickup. The transition map alone allows both, because `ready` legitimately fans out
     * to three places — the order type is what narrows it.
     */
    public function test_the_handover_state_has_to_match_the_order_type(): void
    {
        $pickup = $this->order(OrderType::Pickup);
        $this->machine->transition($pickup, OrderStatus::Accepted, $this->staff());
        $this->machine->transition($pickup, OrderStatus::Preparing, $this->staff());
        $this->machine->transition($pickup, OrderStatus::Ready, $this->staff());

        try {
            $this->machine->transition($pickup, OrderStatus::OutForDelivery, $this->staff());
            $this->fail('A pickup order went out for delivery.');
        } catch (IllegalTransition $refused) {
            $this->assertSame(OrderStatus::OutForDelivery, $refused->to);
        }

        $delivery = $this->order(OrderType::Delivery);
        $this->machine->transition($delivery, OrderStatus::Accepted, $this->staff());
        $this->machine->transition($delivery, OrderStatus::Preparing, $this->staff());
        $this->machine->transition($delivery, OrderStatus::Ready, $this->staff());

        $this->expectException(IllegalTransition::class);
        $this->machine->transition($delivery, OrderStatus::ReadyForPickup, $this->staff());
    }

    public function test_moving_to_the_state_it_is_already_in_is_a_no_op(): void
    {
        $order = $this->order();

        $this->machine->transition($order, OrderStatus::Accepted, $this->staff());
        $this->machine->transition($order, OrderStatus::Accepted, $this->staff());

        // Two staff tapping "Accept" at once is an ordinary Friday. One audit row, no error.
        $this->assertSame(1, $order->statusHistory()->where('to_status', 'accepted')->count());
    }

    // ── Cancellation (brief §5.4) ─────────────────────────────────────────────

    /**
     * ⚠️ THE RESTORE POINT IS STORED, NOT RE-DERIVED.
     *
     * Guessing where the order "probably was" is how one ends up back in `received` after
     * the food is already cooked.
     */
    public function test_a_rejected_cancellation_restores_the_exact_previous_status(): void
    {
        $order = $this->order();

        $this->machine->transition($order, OrderStatus::Accepted, $this->staff());
        $this->machine->transition($order, OrderStatus::Preparing, $this->staff());
        $this->machine->transition($order, OrderStatus::CancelRequested, null, 'Customer changed their mind');

        $this->assertSame('preparing', $order->fresh()->cancel_previous_status);

        $this->machine->rejectCancellation($order, $this->staff(), 'Already on the stove');

        $restored = $order->fresh();

        $this->assertSame(OrderStatus::Preparing, $restored->status);
        $this->assertNull($restored->cancel_previous_status);
        $this->assertNull($restored->cancel_requested_at);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'cancel_requested',
            'to_status' => 'preparing',
            'note' => 'Already on the stove',
        ]);
    }

    public function test_rejecting_a_cancellation_nobody_asked_for_is_refused(): void
    {
        $order = $this->order();

        $this->expectException(IllegalTransition::class);

        $this->machine->rejectCancellation($order, $this->staff());
    }

    public function test_an_accepted_cancellation_ends_at_cancelled(): void
    {
        $order = $this->order();

        $this->machine->transition($order, OrderStatus::CancelRequested, null, 'Customer changed their mind');
        $this->machine->transition($order, OrderStatus::Cancelled, $this->staff(), 'Agreed');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertTrue($order->fresh()->status->isTerminal());
    }

    // ── The enum's own rules ──────────────────────────────────────────────────

    /**
     * The database CHECK constraints and this enum have to agree, and they are written down
     * in two different files. The original widened one and forgot the other, and the failure
     * surfaced as a 500 on the audit write long after the status change looked like it had
     * worked (brief trap §10.11).
     */
    public function test_every_status_is_accepted_by_both_check_constraints(): void
    {
        $order = $this->order();

        foreach (OrderStatus::cases() as $status) {
            Order::query()->where('id', $order->id)->update(['status' => $status->value]);

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => $status->value,
            ]);
        }

        $this->assertSame(
            count(OrderStatus::cases()),
            $order->statusHistory()->whereNull('from_status')->count() - 1,
            'The placement row plus one per status.',
        );
    }
}
