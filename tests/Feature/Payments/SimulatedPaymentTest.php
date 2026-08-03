<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\CycleStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Services\Money\Insights;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Payments\PaymentInitiator;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settling an order without money, and the guarantee that it can never be mistaken for one
 * that was settled with money.
 *
 * The mode exists so the lifecycle can be rehearsed and demonstrated before real customers
 * arrive. The whole risk of it is that a rehearsal leaks into the numbers, so most of what
 * is asserted below is about the marking rather than about the settling.
 */
final class SimulatedPaymentTest extends TestCase
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

    private function simulateMode(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'payment_mode'],
            ['value' => 'simulate', 'cast' => 'string', 'group' => 'payments', 'is_public' => false],
        );
        SystemSetting::flush();
    }

    private function order(): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],   // 8000 pesewas
            type: OrderType::Pickup,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $this->day->id,
        ));
    }

    // ── Settling ──────────────────────────────────────────────────────────────

    public function test_a_simulated_payment_settles_the_order(): void
    {
        $this->simulateMode();
        $order = $this->order();

        $attempt = app(PaymentInitiator::class)->begin($order);
        $this->assertTrue($attempt->wasStarted());

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/simulate", [
            'reference' => $attempt->payment->reference,
            'outcome' => 'success',
        ])->assertOk()->assertJsonPath('data.paid', true);

        $this->assertTrue($order->fresh()->is_paid);
    }

    /** The sad path is half the reason to rehearse: an order that cannot fail is untested. */
    public function test_a_simulated_payment_can_fail(): void
    {
        $this->simulateMode();
        $order = $this->order();

        $attempt = app(PaymentInitiator::class)->begin($order);

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/simulate", [
            'reference' => $attempt->payment->reference,
            'outcome' => 'failed',
        ])->assertOk()->assertJsonPath('data.paid', false);

        $this->assertFalse($order->fresh()->is_paid);
        $this->assertSame(PaymentStatus::Failed, $attempt->payment->fresh()->status);
    }

    // ── The marking, which is the whole point ─────────────────────────────────

    public function test_both_the_payment_and_the_order_are_marked_simulated(): void
    {
        $this->simulateMode();
        $order = $this->order();

        $attempt = app(PaymentInitiator::class)->begin($order);

        $this->assertTrue($attempt->payment->is_simulated);
        // NOT 'paystack' — a settlement import matching on provider must never pick it up.
        $this->assertSame('simulated', $attempt->payment->provider);
        $this->assertTrue($order->fresh()->is_simulated);
    }

    /**
     * ⚠️ THE ONE THAT MATTERS. A rehearsal must never reach her takings.
     */
    public function test_a_simulated_order_is_excluded_from_every_revenue_figure(): void
    {
        $this->simulateMode();
        $order = $this->order();

        $attempt = app(PaymentInitiator::class)->begin($order);
        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/simulate", [
            'reference' => $attempt->payment->reference,
            'outcome' => 'success',
        ])->assertOk();

        $this->assertTrue($order->fresh()->is_paid, 'Precondition: it really is marked paid.');

        $insights = app(Insights::class)->between(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertSame(0, $insights['revenue']['total']);
        $this->assertSame(0, $insights['revenue']['paid']);
        $this->assertSame(0, $insights['revenue']['gross']);
        $this->assertSame(0, $insights['revenue']['order_count']);
    }

    /**
     * The order is flagged when the attempt BEGINS, not when it succeeds — otherwise an
     * abandoned rehearsal quietly inflates the "unpaid" figure instead of the paid one.
     */
    public function test_an_abandoned_simulation_is_still_out_of_the_figures(): void
    {
        $this->simulateMode();
        $order = $this->order();

        app(PaymentInitiator::class)->begin($order);   // never settled

        $insights = app(Insights::class)->between(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertSame(0, $insights['revenue']['unpaid']);
        $this->assertSame(0, $insights['revenue']['order_count']);
    }

    // ── The guards ────────────────────────────────────────────────────────────

    public function test_simulation_is_refused_when_the_mode_is_live(): void
    {
        $this->simulateMode();
        $order = $this->order();
        $attempt = app(PaymentInitiator::class)->begin($order);

        // Flipped back mid-rehearsal. The half-finished attempt becomes un-settleable,
        // which is the correct direction to fail.
        SystemSetting::query()->where('key', 'payment_mode')->update(['value' => 'live']);
        SystemSetting::flush();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/simulate", [
            'reference' => $attempt->payment->reference,
            'outcome' => 'success',
        ])->assertStatus(422);

        $this->assertFalse($order->fresh()->is_paid);
    }

    /**
     * ⚠️ A REAL PAYSTACK ATTEMPT CAN NEVER BE SETTLED THROUGH THIS ENDPOINT, whatever the
     * mode says. Without the `is_simulated` check on the row, turning the toggle on would
     * hand anyone holding a tracking token a way to mark a genuine order paid.
     */
    public function test_a_real_payment_row_cannot_be_settled_by_simulation(): void
    {
        $order = $this->order();

        $real = $order->payments()->create([
            'provider' => 'paystack',
            'is_simulated' => false,
            'reference' => 'mefs_real_reference',
            'amount' => $order->total,
            'currency' => 'GHS',
            'status' => PaymentStatus::Pending->value,
        ]);

        $this->simulateMode();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/simulate", [
            'reference' => $real->reference,
            'outcome' => 'success',
        ])->assertStatus(404);

        $this->assertFalse($order->fresh()->is_paid);
        $this->assertSame(PaymentStatus::Pending, $real->fresh()->status);
    }

    public function test_live_mode_does_not_mark_anything_simulated(): void
    {
        $order = $this->order();

        // No keys configured, so this comes back `unavailable` — the point is only that
        // nothing was flagged on the way through.
        app(PaymentInitiator::class)->begin($order);

        $this->assertFalse($order->fresh()->is_simulated);
    }
}
