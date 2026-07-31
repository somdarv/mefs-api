<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
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
 * The back office order desk.
 *
 * ⚠️ THE TEST THIS FILE EXISTS FOR is `test_manual_entry_is_refused_by_the_same_gate`.
 *
 * Everything else here is ordinary CRUD assurance. That one asserts that the route the till
 * uses is gated — which is precisely what the original never checked, and why 23 portions
 * were sold against a balance of 6 four minutes after the gate deployed (brief §5.8, §10.9).
 */
final class OrderAdminTest extends TestCase
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
            ['is_available' => true],
        );

        $this->waakye = $item->options()->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    /**
     * Take one permission away from the admin ROLE.
     *
     * ⚠️ FROM THE ROLE, NOT FROM THE USER. `revokePermissionTo()` on a user removes only
     * DIRECT grants, so revoking a role-derived permission that way is a silent no-op — the
     * check still passes and the test still goes green while asserting nothing (brief §4.4).
     *
     * v1 has no staff role that lacks these permissions, so this is how the two checks are
     * shown to be distinct: the permission split is forward-looking, and the assertion has
     * to be too.
     */
    private function revokeFromAdminRole(Permission $permission): void
    {
        // `'web'` explicitly. Roles and permissions are seeded on the web guard, while the
        // app's DEFAULT guard is `sanctum` — so `findByName()` with no guard looks for a
        // role that does not exist and throws.
        SpatieRole::findByName(Role::Admin->value, 'web')->revokePermissionTo($permission->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetAuth();
    }

    private function asStaff(User $user): static
    {
        // `staff` is the ability the staff login path mints, and the middleware checks it
        // with an explicit in_array — never `can()`, which honours the `*` wildcard that
        // `createToken($name)` hands out by default.
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    /** @return array<string, mixed> */
    private function manualPayload(array $overrides = []): array
    {
        return array_merge([
            'lines' => [['menu_item_option_id' => $this->waakye->id, 'quantity' => 3]],
            'order_type' => 'pickup',
            'source' => 'whatsapp',
            'cycle_day_id' => $this->day->id,
            'contact_name' => 'Kwame Boateng',
            'contact_phone' => '0244000111',
            'internal_notes' => 'Pays by MoMo after the call',
        ], $overrides);
    }

    // ── Manual entry ──────────────────────────────────────────────────────────

    public function test_manual_entry_creates_an_order_through_the_shared_service(): void
    {
        $response = $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', $this->manualPayload())
            ->assertCreated();

        $this->assertSame('A001', $response->json('data.order_number'));
        $this->assertSame('whatsapp', $response->json('data.source'));
        $this->assertTrue($response->json('data.is_manual_entry'));
        $this->assertSame(12000, $response->json('data.total'));
        $this->assertSame('Pays by MoMo after the call', $response->json('data.internal_notes'));

        // Departure #6: hers may hold a slot unpaid, and the clock on that is visible to
        // her and to nobody else.
        $this->assertNotNull($response->json('data.slot_hold_expires_at'));
        $this->assertFalse($response->json('data.is_paid'));

        // Same portion ledger as the customer path.
        // Filtered by dish: every meal has a row on every day, so an unfiltered read here
        // would return whichever one sorts first and pass or fail by accident.
        $this->assertSame(3, (int) CycleDayItem::query()
            ->where('cycle_day_id', $this->day->id)
            ->where('menu_item_id', $this->waakye->menu_item_id)
            ->value('portions_sold'));
    }

    /**
     * ⚠️ THE ONE THAT MATTERS.
     *
     * The endpoint she actually uses is behind the same gate as the customer's. If this
     * ever fails, the gate is decorating a route nobody drives.
     */
    public function test_manual_entry_is_refused_by_the_same_gate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-04T18:00:01Z'));

        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', $this->manualPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.refusal.reason', 'cutoff_passed');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_manual_entry_cannot_mint_an_online_order(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', $this->manualPayload(['source' => 'online']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);
    }

    public function test_manual_entry_needs_the_orders_create_permission(): void
    {
        $this->revokeFromAdminRole(Permission::OrdersCreate);

        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', $this->manualPayload())
            ->assertForbidden();

        $this->assertSame(0, Order::query()->count());
    }

    // ── The pipeline ──────────────────────────────────────────────────────────

    public function test_the_list_opens_on_live_orders_not_on_everything_ever_placed(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $second = Order::query()->orderByDesc('id')->firstOrFail();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$second->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        $live = $this->asStaff($this->admin())->getJson('/api/v1/admin/orders')->assertOk();
        $this->assertCount(1, $live->json('data.orders'));

        $all = $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/orders?include_finished=1')
            ->assertOk();
        $this->assertCount(2, $all->json('data.orders'));
    }

    public function test_the_list_is_searchable_by_number_name_and_phone(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        foreach (['A001', 'kwame', '0244000111'] as $needle) {
            $found = $this->asStaff($this->admin())
                ->getJson('/api/v1/admin/orders?search='.urlencode($needle))
                ->assertOk();

            $this->assertCount(1, $found->json('data.orders'), "Search for {$needle} found nothing.");
        }
    }

    public function test_the_staff_payload_carries_what_the_customer_payload_does_not(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $order = Order::query()->firstOrFail();

        $data = $this->asStaff($this->admin())
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('internal_notes', $data);
        $this->assertArrayHasKey('slot_hold_expires_at', $data);
        $this->assertArrayHasKey('revenue_total', $data);
        $this->assertSame(['accepted', 'cancel_requested', 'cancelled'], $data['allowed_transitions']);
        $this->assertSame('Mef', $data['status_history'][0]['actor_name']);
    }

    // ── Moving orders along ───────────────────────────────────────────────────

    public function test_a_status_change_is_refused_when_the_machine_says_no(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $order = Order::query()->firstOrFail();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $this->assertSame('received', $order->fresh()->status->value);
    }

    public function test_a_pickup_order_cannot_be_sent_out_for_delivery(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $order = Order::query()->firstOrFail();

        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->asStaff($this->admin())
                ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status])
                ->assertOk();
        }

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'out_for_delivery'])
            ->assertStatus(422);
    }

    public function test_a_rejected_cancellation_returns_the_order_to_where_it_was(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $order = Order::query()->firstOrFail();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertOk();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancel_requested'])
            ->assertOk();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/reject-cancellation", ['note' => 'Already shopping for it'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    /**
     * "Mark the food ready" and "cancel a paid order" are not the same sentence, so they are
     * not the same permission — one endpoint, two checks, chosen by the destination status.
     */
    public function test_cancelling_is_a_different_permission_from_advancing(): void
    {
        $this->asStaff($this->admin())->postJson('/api/v1/admin/orders', $this->manualPayload())->assertCreated();

        $order = Order::query()->firstOrFail();

        $this->revokeFromAdminRole(Permission::OrdersCancel);

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertOk();

        $this->forgetAuth();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertSame('accepted', $order->fresh()->status->value);
    }
}
