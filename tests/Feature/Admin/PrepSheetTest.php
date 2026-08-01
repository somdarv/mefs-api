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
 * The prep sheet — what to cook, totalled per pot.
 *
 * Three of these tests exist because the aggregation has three ways to be silently wrong,
 * and every one of them produces a sheet that looks right and sends the wrong amount of
 * food to the stove: counting rows, collapsing options to the dish, and including cancelled
 * orders. A sheet is only ever checked against reality at 6am.
 */
final class PrepSheetTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    private CycleDay $day;

    private MenuItem $etor;

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

        // Etor is the dish that matters here: it has more than one option, which is the
        // whole reason the sheet groups by option rather than by dish.
        $this->etor = MenuItem::query()->where('slug', 'plantain-etor')->firstOrFail();

        foreach ([$this->etor, MenuItem::query()->where('slug', 'waakye')->firstOrFail()] as $item) {
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

    /** Place an order through the real path — there is only one, and this is it. */
    private function place(array $lines, array $overrides = []): int
    {
        $response = $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', array_merge([
                'lines' => $lines,
                'order_type' => 'pickup',
                'source' => 'whatsapp',
                'cycle_day_id' => $this->day->id,
                'contact_name' => 'Kwame Boateng',
                'contact_phone' => '0244000111',
            ], $overrides))
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function sheet(?string $date = null): array
    {
        return $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/prep-sheet?date='.($date ?? $this->day->date->toDateString()))
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed>|null */
    private function dishFor(array $sheet, int $optionId): ?array
    {
        foreach ($sheet['dishes'] as $dish) {
            if ($dish['menu_item_option_id'] === $optionId) {
                return $dish;
            }
        }

        return null;
    }

    // ── The three ways to get it wrong ────────────────────────────────────────

    /**
     * ⚠️ TWO LINES OF THREE PORTIONS IS SIX PORTIONS.
     *
     * Counting rows gives 2 and looks like a plausible number, which is exactly why this is
     * worth an assertion rather than a glance.
     */
    public function test_it_sums_quantities_rather_than_counting_rows(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail()->options()->firstOrFail();

        $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 3]]);
        $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 3]]);

        $dish = $this->dishFor($this->sheet(), $waakye->id);

        $this->assertNotNull($dish);
        $this->assertSame(6, $dish['portions'], 'Rows were counted instead of quantities summed.');
        $this->assertSame(2, $dish['order_count']);
    }

    /**
     * ⚠️ ONE POT, TWO THINGS TO COOK.
     *
     * A standard Etor and a plain Etor come out of the same pot but are not the same job.
     * Collapsing them to the dish makes the sheet wrong in a way she discovers at the stove.
     */
    public function test_options_of_one_dish_stay_separate(): void
    {
        $options = $this->etor->options()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $options->count(), 'Etor needs two options to test this.');

        /** @var MenuOption $standard */
        $standard = $options[0];
        /** @var MenuOption $other */
        $other = $options[1];

        $this->place([
            ['menu_item_option_id' => $standard->id, 'quantity' => 6],
            ['menu_item_option_id' => $other->id, 'quantity' => 3],
        ]);

        $sheet = $this->sheet();

        $this->assertSame(6, $this->dishFor($sheet, $standard->id)['portions']);
        $this->assertSame(3, $this->dishFor($sheet, $other->id)['portions']);
        $this->assertSame(9, $sheet['total_portions']);
    }

    /**
     * A cancelled order gives its slot back, so it also gives its portions back. Same
     * definition the capacity numbers use — `Order::scopeHoldingCapacity()`, not a fourth
     * hand-written `where status !=`.
     */
    public function test_a_cancelled_order_leaves_the_sheet(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail()->options()->firstOrFail();

        $keep = $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 4]]);
        $drop = $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 10]]);

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$drop}/status", ['status' => 'cancelled'])
            ->assertOk();

        $sheet = $this->sheet();

        $this->assertSame(4, $sheet['total_portions']);
        $this->assertSame(1, $sheet['order_count']);
        $this->assertSame($keep, Order::query()->holdingCapacity()->value('id'));
    }

    // ── What belongs on it, and what does not ─────────────────────────────────

    /**
     * A shipped jar of jollof base is not something to cook on Wednesday. It holds no
     * cooking date at all, so a pantry line on the sheet is a line she cannot act on.
     */
    public function test_pantry_lines_are_not_on_a_cooking_sheet(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail()->options()->firstOrFail();
        $jar = MenuItem::query()->where('category', 'pantry')->firstOrFail()->options()->firstOrFail();

        $this->place([
            ['menu_item_option_id' => $waakye->id, 'quantity' => 2],
            ['menu_item_option_id' => $jar->id, 'quantity' => 5],
        ]);

        $sheet = $this->sheet();

        $this->assertSame(2, $sheet['total_portions']);
        $this->assertCount(1, $sheet['dishes']);
        $this->assertNull($this->dishFor($sheet, $jar->id));
    }

    /** "No pepper" is useless on a screen she cannot see it on. */
    public function test_notes_travel_with_the_dish_they_belong_to(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail()->options()->firstOrFail();

        $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 1, 'notes' => 'No pepper']]);
        $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 2]]);

        $dish = $this->dishFor($this->sheet(), $waakye->id);

        $this->assertSame(3, $dish['portions']);
        $this->assertCount(1, $dish['notes'], 'A line with no note should not produce an empty note.');
        $this->assertSame('No pepper', $dish['notes'][0]['note']);
        $this->assertSame('A001', $dish['notes'][0]['order_number']);
    }

    public function test_another_day_is_empty_rather_than_wrong(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail()->options()->firstOrFail();

        $this->place([['menu_item_option_id' => $waakye->id, 'quantity' => 4]]);

        $sheet = $this->sheet('2026-08-11');

        $this->assertSame([], $sheet['dishes']);
        $this->assertSame(0, $sheet['total_portions']);
    }

    public function test_it_needs_the_orders_view_permission(): void
    {
        SpatieRole::findByName(Role::Admin->value, 'web')
            ->revokePermissionTo(Permission::OrdersView->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetAuth();

        $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/prep-sheet?date=2026-08-05')
            ->assertForbidden();
    }

    public function test_the_date_is_required_and_validated(): void
    {
        $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/prep-sheet')
            ->assertStatus(422);

        $this->asStaff($this->admin())
            ->getJson('/api/v1/admin/prep-sheet?date=5%20August')
            ->assertStatus(422);
    }
}
