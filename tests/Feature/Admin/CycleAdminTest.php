<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\CycleOverride;
use App\Enums\Permission;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Models\User;
use App\Services\Ordering\CycleBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CycleAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function asAdmin(): static
    {
        return $this->forgetAuth()->withToken(
            $this->admin->createToken('staff', ['staff'])->plainTextToken,
        );
    }

    /** Her scenario: orders 1-4 Aug, cooking 5-12 Aug. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ], $overrides);
    }

    private function existing(): OrderCycle
    {
        return app(CycleBuilder::class)->create($this->payload());
    }

    // ── Creating ──────────────────────────────────────────────────────────────

    public function test_she_can_create_a_cycle_with_two_separate_windows(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/cycles', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.service_window.start_date', '2026-08-05')
            ->assertJsonPath('data.service_window.end_date', '2026-08-12')
            ->assertJsonPath('data.service_window.day_count', 8)
            ->assertJsonPath('data.ordering_window.opens_at', '2026-08-01T00:00:00+00:00')
            ->assertJsonPath('data.ordering_window.closes_at', '2026-08-04T18:00:00+00:00')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(8, 'data.days');
    }

    public function test_the_matrix_arrives_prefilled(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/cycles', $this->payload());

        $wednesday = collect($response->json('data.days'))->firstWhere('weekday', 3);

        $this->assertNotEmpty($wednesday['items']);
        $this->assertTrue(
            collect($wednesday['items'])->contains('is_available', true),
            'Wednesday has no dish ticked, so the matrix was not pre-filled from the rotation.',
        );
    }

    /**
     * Two live cycles covering one date makes "which cycle owns 6 August" ambiguous. The
     * database refuses it; this asserts the API turns that into something she can read
     * rather than a 500.
     */
    public function test_overlapping_cooking_dates_are_refused_with_a_readable_error(): void
    {
        $this->existing();

        $this->asAdmin()
            ->postJson('/api/v1/admin/cycles', $this->payload([
                'service_start_date' => '2026-08-10',
                'service_end_date' => '2026-08-17',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['service_start_date']]);
    }

    public function test_orders_cannot_close_after_the_last_cooking_day(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/cycles', $this->payload([
                'orders_close_at' => '2026-08-20T18:00:00Z',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['orders_close_at']]);
    }

    public function test_orders_must_close_after_they_open(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/cycles', $this->payload([
                'orders_open_at' => '2026-08-04T18:00:00Z',
                'orders_close_at' => '2026-08-01T00:00:00Z',
            ]))
            ->assertStatus(422);
    }

    // ── Publishing ────────────────────────────────────────────────────────────

    public function test_publishing_makes_a_cycle_visible(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull($cycle->fresh()->published_at);
    }

    /**
     * An empty menu reads to a customer as "she isn't cooking", not "this isn't set up
     * yet". Refusing to publish one is cheaper than explaining the difference later.
     */
    public function test_a_cycle_with_no_dishes_anywhere_cannot_be_published(): void
    {
        $cycle = $this->existing();
        $cycle->days->each(fn ($d) => $d->items()->update(['is_available' => false]));

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/publish")
            ->assertStatus(422);
    }

    // ── The switch ────────────────────────────────────────────────────────────

    public function test_she_can_force_orders_closed_with_a_reason(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/override", [
                'override' => 'force_closed',
                'reason' => 'Fully booked',
            ])
            ->assertOk()
            ->assertJsonPath('data.override', 'force_closed')
            ->assertJsonPath('data.override_reason', 'Fully booked');

        $this->assertSame($this->admin->id, $cycle->fresh()->override_by);
    }

    public function test_she_can_reopen_orders(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/override", [
                'override' => 'force_open',
                'reason' => 'Regular rang after close',
            ])
            ->assertOk()
            ->assertJsonPath('data.override', 'force_open');
    }

    public function test_clearing_the_override_returns_to_the_schedule(): void
    {
        $cycle = $this->existing();
        $cycle->applyOverride(CycleOverride::ForceClosed, 'Ill', $this->admin);

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/override", ['override' => null])
            ->assertOk()
            ->assertJsonPath('data.override', null)
            ->assertJsonPath('data.override_reason', null);
    }

    /** "Why were we closed on the 6th?" is the only question that matters a week later. */
    public function test_an_override_without_a_reason_is_refused(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/override", ['override' => 'force_closed'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    /**
     * Editing next week's plan and shutting the shop right now are different acts. Someone
     * who can do the first must not automatically be able to do the second.
     */
    public function test_overriding_needs_its_own_permission(): void
    {
        SpatieRole::findByName('admin', 'web')->revokePermissionTo(Permission::CyclesOverride->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/override", [
                'override' => 'force_closed', 'reason' => 'x',
            ])
            ->assertForbidden();

        // She can still edit the cycle itself.
        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", ['name' => 'Renamed'])
            ->assertOk();
    }

    // ── Moving the cooking window ─────────────────────────────────────────────

    public function test_she_can_move_a_drafts_cooking_dates(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", [
                'service_start_date' => '2026-08-06',
                'service_end_date' => '2026-08-11',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_window.start_date', '2026-08-06')
            ->assertJsonPath('data.service_window.end_date', '2026-08-11')
            ->assertJsonPath('data.service_window.day_count', 6);

        $dates = $cycle->fresh('days')->days->pluck('date')
            ->map(fn ($d) => $d->toDateString())->sort()->values()->all();

        $this->assertSame(
            ['2026-08-06', '2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11'],
            $dates,
        );
    }

    /**
     * The whole reason reshape is surgical rather than a delete-and-rebuild: the matrix is
     * the work, and a date that survives the move must keep the edits made against it.
     */
    public function test_moving_the_window_keeps_the_matrix_on_days_that_survive(): void
    {
        $cycle = $this->existing();

        $survivor = $cycle->days->firstWhere(fn ($d) => $d->date->toDateString() === '2026-08-06');
        $meal = MenuItem::query()->first();
        $survivor->items()->where('menu_item_id', $meal->id)
            ->update(['is_available' => true, 'portion_capacity' => 42]);
        $survivor->update(['kitchen_note' => 'Prep the beans']);

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", [
                'service_start_date' => '2026-08-06',
                'service_end_date' => '2026-08-14',
            ])
            ->assertOk();

        $kept = $cycle->fresh('days.items')->days
            ->firstWhere(fn ($d) => $d->date->toDateString() === '2026-08-06');

        $this->assertSame('Prep the beans', $kept->kitchen_note);
        $this->assertSame(42, $kept->items->firstWhere('menu_item_id', $meal->id)->portion_capacity);

        // And the dates that arrived got built, pre-filled like any new day.
        $this->assertNotNull(
            $cycle->fresh('days')->days->firstWhere(fn ($d) => $d->date->toDateString() === '2026-08-14'),
        );
    }

    /**
     * The guard that was there before this feature, and still is. A published cycle can have
     * orders against it, and dropping a day they point at would orphan them.
     */
    public function test_a_published_cycles_cooking_dates_cannot_move(): void
    {
        $cycle = $this->existing();
        $cycle->days->each(fn ($d) => $d->items()->limit(1)->update(['is_available' => true]));

        $this->asAdmin()->postJson("/api/v1/admin/cycles/{$cycle->id}/publish")->assertOk();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", [
                'service_start_date' => '2026-08-06',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_start_date');

        $this->assertSame('2026-08-05', $cycle->fresh()->service_start_date->toDateString());
    }

    /** Its ordering window still moves, though — that is what the switch's schedule reads. */
    public function test_a_published_cycles_ordering_window_still_moves(): void
    {
        $cycle = $this->existing();
        $cycle->days->each(fn ($d) => $d->items()->limit(1)->update(['is_available' => true]));
        $this->asAdmin()->postJson("/api/v1/admin/cycles/{$cycle->id}/publish")->assertOk();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", [
                'orders_close_at' => '2026-08-04T21:00:00Z',
            ])
            ->assertOk();

        $this->assertSame(
            '2026-08-04 21:00:00',
            $cycle->fresh()->orders_close_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * ⚠️ THE PARTIAL-WRITE CASE. The cross-window rule can only be judged after both windows
     * are settled, so rejecting it happens after the ordering window has already been
     * written. Without the transaction, the refusal would itself leave the cycle in the
     * inconsistent state it exists to prevent.
     */
    public function test_a_rejected_cross_window_edit_leaves_nothing_behind(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", [
                'orders_close_at' => '2026-08-20T18:00:00Z',
                'service_end_date' => '2026-08-10',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('orders_close_at');

        $fresh = $cycle->fresh('days');

        // Neither half landed.
        $this->assertSame('2026-08-04 18:00:00', $fresh->orders_close_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-12', $fresh->service_end_date->toDateString());
        $this->assertCount(8, $fresh->days);
    }

    public function test_the_last_cooking_day_cannot_move_before_the_first(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", ['service_end_date' => '2026-08-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_end_date');
    }

    public function test_moving_a_window_onto_another_cycle_is_refused(): void
    {
        $cycle = $this->existing();

        app(CycleBuilder::class)->create($this->payload([
            'name' => 'Week of 20 Aug',
            'service_start_date' => '2026-08-20',
            'service_end_date' => '2026-08-26',
            'orders_open_at' => '2026-08-14T00:00:00Z',
            'orders_close_at' => '2026-08-19T18:00:00Z',
        ]));

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}", ['service_end_date' => '2026-08-22'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_start_date');
    }

    // ── Days and the matrix ───────────────────────────────────────────────────

    public function test_she_can_close_a_single_day(): void
    {
        $cycle = $this->existing();
        $day = $cycle->days->first();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$cycle->id}/days/{$day->id}", [
                'is_open' => false,
                'kitchen_note' => 'Funeral',
            ])
            ->assertOk();

        $this->assertFalse($day->fresh()->is_open);
    }

    public function test_she_can_cap_portions_of_one_dish_on_one_day(): void
    {
        $cycle = $this->existing();
        $day = $cycle->days->first();
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->asAdmin()
            ->putJson("/api/v1/admin/cycles/{$cycle->id}/days/{$day->id}/items", [
                'items' => [
                    ['menu_item_id' => $waakye->id, 'is_available' => true, 'portion_capacity' => 20],
                ],
            ])
            ->assertOk();

        $cell = $day->fresh('items')->items->firstWhere('menu_item_id', $waakye->id);
        $this->assertTrue($cell->is_available);
        $this->assertSame(20, $cell->portion_capacity);
    }

    /**
     * Nested bindings resolve independently, so without an explicit ownership check a
     * caller can pass any day id under any cycle id. Brief Law 2 in miniature.
     */
    public function test_a_day_from_another_cycle_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->existing();
        $other = app(CycleBuilder::class)->create($this->payload([
            'service_start_date' => '2026-09-01',
            'service_end_date' => '2026-09-05',
            'orders_open_at' => '2026-08-25T00:00:00Z',
            'orders_close_at' => '2026-08-30T18:00:00Z',
        ]));

        $this->asAdmin()
            ->patchJson("/api/v1/admin/cycles/{$mine->id}/days/{$other->days->first()->id}", ['is_open' => false])
            ->assertNotFound();

        $this->assertTrue($other->days->first()->fresh()->is_open);
    }

    // ── Cloning ───────────────────────────────────────────────────────────────

    public function test_she_can_clone_a_cycle_forward(): void
    {
        $cycle = $this->existing();

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/clone")
            ->assertCreated()
            ->assertJsonPath('data.service_window.start_date', '2026-08-13')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_cloning_onto_an_occupied_week_is_refused_readably(): void
    {
        $cycle = $this->existing();
        // Occupy the slot the default clone would land on.
        app(CycleBuilder::class)->create($this->payload([
            'service_start_date' => '2026-08-13',
            'service_end_date' => '2026-08-20',
            'orders_open_at' => '2026-08-09T00:00:00Z',
            'orders_close_at' => '2026-08-12T18:00:00Z',
        ]));

        $this->asAdmin()
            ->postJson("/api/v1/admin/cycles/{$cycle->id}/clone")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['service_start_date']]);
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_a_customer_token_cannot_reach_the_cycle_editor(): void
    {
        $customer = User::factory()->customer()->create();

        $this->withToken($customer->createToken('c', ['customer'])->plainTextToken)
            ->getJson('/api/v1/admin/cycles')
            ->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_reach_the_cycle_editor(): void
    {
        $this->getJson('/api/v1/admin/cycles')->assertUnauthorized();
    }
}
