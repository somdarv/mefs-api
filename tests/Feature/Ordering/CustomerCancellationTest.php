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
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderStatusMachine;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer asking to cancel, and the kitchen answering.
 *
 * `cancel_requested` and `rejectCancellation()` were built as a matched pair and only the
 * answering half was ever reachable — there was no customer route, so the state could only
 * be entered from the back office's own buttons, which meant she requested a cancellation on
 * her own order and then approved her own request. These tests cover the half that was
 * missing, and the round trip it completes.
 */
final class CustomerCancellationTest extends TestCase
{
    use RefreshDatabase;

    private CycleDay $day;

    private MenuOption $waakye;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));

        $cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);
        $cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $cycle->days()->orderBy('date')->firstOrFail();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
            ['is_available' => true],
        );

        $this->waakye = $item->options()->firstOrFail();
    }

    private function order(): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],
            type: OrderType::Pickup,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $this->day->id,
        ));
    }

    private function ask(Order $order, string $reason = 'My plans changed')
    {
        return $this->postJson(
            "/api/v1/orders/{$order->tracking_token}/cancel-request",
            ['reason' => $reason],
        );
    }

    // ── Asking ────────────────────────────────────────────────────────────────

    /** ⚠️ IT ASKS, IT DOES NOT CANCEL. She may already have shopped for this order. */
    public function test_a_customer_can_ask_to_cancel_and_the_order_is_not_cancelled(): void
    {
        $order = $this->order();

        $this->ask($order)->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CancelRequested, $fresh->status);
        $this->assertNotSame(OrderStatus::Cancelled, $fresh->status);
    }

    /** The three columns that nothing used to write. */
    public function test_who_asked_and_why_is_recorded(): void
    {
        $order = $this->order();

        $this->ask($order, 'I ordered the wrong day')->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('I ordered the wrong day', $fresh->cancel_request_reason);
        $this->assertNotNull($fresh->cancel_requested_at);
        // Name AND number, in the normalised +233 form the order stores — this string is
        // what she reads before picking up the phone to answer the request.
        $this->assertStringContainsString('Ama Serwaa', (string) $fresh->cancel_requested_by);
        $this->assertStringContainsString('+233241234567', (string) $fresh->cancel_requested_by);
    }

    /**
     * ⚠️ THE RESTORE POINT IS STORED ON THE WAY IN. Without it `rejectCancellation()` has
     * nowhere to put the order back and refuses — leaving it stuck with no way out but SQL.
     */
    public function test_the_previous_status_is_stored_so_a_rejection_has_somewhere_to_go(): void
    {
        $order = $this->order();
        app(OrderStatusMachine::class)->transition($order, OrderStatus::Accepted);

        $this->ask($order)->assertOk();

        $this->assertSame(OrderStatus::Accepted->value, $order->fresh()->cancel_previous_status);
    }

    public function test_a_reason_is_required(): void
    {
        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/cancel-request", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /** Tapping twice on a slow connection must not overwrite the reason already recorded. */
    public function test_asking_twice_is_idempotent(): void
    {
        $order = $this->order();

        $this->ask($order, 'The first reason')->assertOk();
        $this->ask($order, 'A second reason')->assertOk();

        $this->assertSame('The first reason', $order->fresh()->cancel_request_reason);
    }

    // ── The window ────────────────────────────────────────────────────────────

    /**
     * Past the point of no return. The window comes from `canMoveTo`, not from a second copy
     * of the rule in the controller — that is how the two drift apart.
     */
    public function test_an_order_already_on_its_way_cannot_be_cancelled_online(): void
    {
        $order = $this->order();
        $machine = app(OrderStatusMachine::class);

        $machine->transition($order, OrderStatus::Accepted);
        $machine->transition($order, OrderStatus::Preparing);
        $machine->transition($order, OrderStatus::Ready);

        $this->ask($order)->assertStatus(422);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->postJson('/api/v1/orders/not-a-real-token/cancel-request', ['reason' => 'x'])
            ->assertNotFound();
    }

    // ── The kitchen answering ─────────────────────────────────────────────────

    /** The round trip the request half was always missing. */
    public function test_the_kitchen_can_reject_and_the_order_returns_exactly_where_it_was(): void
    {
        $order = $this->order();
        app(OrderStatusMachine::class)->transition($order, OrderStatus::Accepted);

        $this->ask($order)->assertOk();

        app(OrderStatusMachine::class)->rejectCancellation($order->fresh());

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Accepted, $fresh->status);
        // And the request is cleared, so the pipeline stops flagging it as needing an answer.
        $this->assertNull($fresh->cancel_requested_at);
        $this->assertNull($fresh->cancel_request_reason);
    }
}
