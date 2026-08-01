<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderCycle;
use App\Models\User;
use App\Services\Ordering\CycleBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The money screen.
 *
 * ⚠️ TWO OF THESE TESTS ARE THE MILESTONE.
 *
 *  - `test_revenue_excludes_the_couriers_share` — counting a pass-through delivery fee as
 *    income overstates every figure on the screen, and the overstatement grows with delivery
 *    volume (brief §5.3).
 *  - `test_insights_needs_analytics_view_not_orders_view` — in the original, analytics sat
 *    behind `view_orders` while `view_analytics` was granted correctly and used by zero
 *    routes. It looked enforced and was not (§4.3.4).
 */
final class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    private CycleDay $day;

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

        foreach (['waakye', 'plantain-etor'] as $slug) {
            $item = MenuItem::query()->where('slug', $slug)->firstOrFail();
            CycleDayItem::query()->updateOrCreate(
                ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
                ['is_available' => true],
            );
        }
    }

    private function admin(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    private function asStaff(User $user): static
    {
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    private function option(string $slug): int
    {
        return (int) MenuItem::query()->where('slug', $slug)->firstOrFail()->options()->value('id');
    }

    private function place(array $overrides = []): int
    {
        $response = $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', array_merge([
                'lines' => [['menu_item_option_id' => $this->option('waakye'), 'quantity' => 2]],
                'order_type' => 'pickup',
                'source' => 'whatsapp',
                'cycle_day_id' => $this->day->id,
                'contact_name' => 'Kwame Boateng',
                'contact_phone' => '0244000111',
            ], $overrides))
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function insights(string $from = '2026-08-01', string $to = '2026-08-31'): array
    {
        return $this->asStaff($this->admin())
            ->getJson("/api/v1/admin/insights?from={$from}&to={$to}")
            ->assertOk()
            ->json('data');
    }

    // ── The one the milestone is for ──────────────────────────────────────────

    /**
     * ⚠️ GROSS IS NOT REVENUE.
     *
     * A delivery fee collected for a third-party courier is handed straight over. Counting
     * it as income overstates every number on the screen, and it overstates them more the
     * busier she gets — the worst direction for a figure to be wrong in.
     */
    public function test_revenue_excludes_the_couriers_share(): void
    {
        $id = $this->place([
            'order_type' => 'delivery',
            'delivery_address' => '14 Ring Road East',
            'delivery_area' => 'Osu',
        ]);

        $order = Order::query()->findOrFail($id);

        $this->assertSame('third_party', $order->delivery_fee_collection);
        $this->assertGreaterThan(0, $order->delivery_fee, 'This test needs a delivery fee to subtract.');

        $revenue = $this->insights()['revenue'];

        $this->assertSame($order->total, $revenue['gross']);
        $this->assertSame($order->total - $order->delivery_fee, $revenue['total']);
        $this->assertSame($order->delivery_fee, $revenue['pass_through_fees']);
    }

    /**
     * ⚠️ ANALYTICS IS NOT BEHIND THE ORDER PERMISSION.
     *
     * Everyone who can look up an order held `view_orders` in the original, and analytics
     * sat behind it. This asserts the two are genuinely distinct rather than merely named
     * differently.
     */
    public function test_insights_needs_analytics_view_not_orders_view(): void
    {
        SpatieRole::findByName(Role::Admin->value, 'web')
            ->revokePermissionTo(Permission::AnalyticsView->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetAuth();

        // Still holds orders.view, and that must not be enough.
        $this->asStaff($this->admin())->getJson('/api/v1/admin/orders')->assertOk();
        $this->forgetAuth();

        $this->asStaff($this->admin())->getJson('/api/v1/admin/insights')->assertForbidden();
    }

    // ── The rest ──────────────────────────────────────────────────────────────

    public function test_a_cancelled_order_is_not_revenue(): void
    {
        $keep = $this->place();
        $drop = $this->place();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$drop}/status", ['status' => 'cancelled'])
            ->assertOk();

        $revenue = $this->insights()['revenue'];

        $this->assertSame(1, $revenue['order_count']);
        $this->assertSame(Order::query()->findOrFail($keep)->total, $revenue['total']);
    }

    /** Unpaid work is not money in the bank, and the two numbers are reported apart. */
    public function test_paid_and_unpaid_are_separate_figures(): void
    {
        $this->place();

        $revenue = $this->insights()['revenue'];

        $this->assertSame(0, $revenue['paid']);
        $this->assertSame($revenue['total'], $revenue['unpaid']);
        $this->assertSame(0, $revenue['paid_count']);
    }

    /**
     * By option, like the prep sheet. A standard Etor and a plain Etor sell at very different
     * prices, and collapsing them hides the fact this table exists to show.
     */
    public function test_dishes_are_broken_out_by_option(): void
    {
        $etor = MenuItem::query()->where('slug', 'plantain-etor')->firstOrFail();
        $options = $etor->options()->orderBy('id')->get();

        $this->place([
            'lines' => [
                ['menu_item_option_id' => $options[0]->id, 'quantity' => 2],
                ['menu_item_option_id' => $options[1]->id, 'quantity' => 1],
            ],
        ]);

        $dishes = $this->insights()['dishes'];

        $this->assertCount(2, $dishes);

        // Ordered by revenue: the pricier option leads even on a smaller quantity.
        $this->assertSame($options[1]->id, $dishes[0]['menu_item_option_id']);
        $this->assertSame(1, $dishes[0]['portions']);
        $this->assertSame($options[1]->price, $dishes[0]['revenue']);
    }

    /** If two thirds of a week arrives on WhatsApp, the storefront is not the business. */
    public function test_channels_split_by_where_the_order_came_from(): void
    {
        $this->place(['source' => 'whatsapp']);
        $this->place(['source' => 'phone']);
        $this->place(['source' => 'phone']);

        $channels = collect($this->insights()['channels'])->keyBy('source');

        $this->assertSame(1, $channels['whatsapp']['order_count']);
        $this->assertSame(2, $channels['phone']['order_count']);
    }

    /**
     * ⚠️ ONLY CAPPED ROWS ARE COUNTED.
     *
     * An uncapped dish can never sell out, so counting it in the denominator would report a
     * kitchen that caps nothing as "0% sold out" — a reassuring number for something that is
     * simply not being measured.
     */
    public function test_sell_outs_count_only_dishes_that_had_a_cap(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        CycleDayItem::query()
            ->where('cycle_day_id', $this->day->id)
            ->where('menu_item_id', $waakye->id)
            ->update(['portion_capacity' => 2]);

        $this->place();  // 2 portions — exactly the cap

        $sellOuts = $this->insights()['sell_outs'];

        $this->assertSame(1, $sellOuts['capped_dish_days'], 'Only the capped row should count.');
        $this->assertSame(1, $sellOuts['sold_out_dish_days']);
        $this->assertSame([$this->day->date->toDateString()], $sellOuts['dates']);
    }

    public function test_a_backwards_window_is_refused_rather_than_returning_nothing(): void
    {
        $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/insights?from=2026-08-31&to=2026-08-01')
            ->assertStatus(422);
    }

    /** A pantry-only order has no fulfil_date, and must not vanish from every figure. */
    public function test_a_pantry_only_order_still_counts(): void
    {
        $jar = MenuItem::query()->where('category', 'pantry')->firstOrFail();

        $this->place([
            'lines' => [['menu_item_option_id' => $jar->options()->value('id'), 'quantity' => 3]],
            'order_type' => 'shipping',
            'cycle_day_id' => null,
            'delivery_address' => '14 Ring Road East',
        ]);

        $this->assertNull(Order::query()->firstOrFail()->fulfil_date);

        $revenue = $this->insights()['revenue'];

        $this->assertSame(1, $revenue['order_count']);
        $this->assertGreaterThan(0, $revenue['total']);
    }
}
