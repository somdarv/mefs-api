<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CycleStatus;
use App\Models\CycleDay;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Services\Ordering\CycleBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The day menu resolves from the CYCLE MATRIX, not the weekday template.
 *
 * That is the whole point of the matrix: she can put Waakye on a Thursday for one week
 * without changing what Waakye means every week.
 */
final class DayMenuFromCycleTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);

        $this->cycle->update(['status' => CycleStatus::Published->value]);
        $this->cycle = $this->cycle->fresh(['days.items']);
    }

    private function dayOn(string $date): CycleDay
    {
        return $this->cycle->days->first(fn (CycleDay $d) => $d->date->toDateString() === $date);
    }

    private function slugsFor(string $date): array
    {
        return array_column(
            $this->getJson("/api/v1/menu/day/{$date}")->assertOk()->json('data.meals'),
            'slug',
        );
    }

    public function test_the_menu_comes_from_the_matrix(): void
    {
        // 5 Aug is a Wednesday, and the matrix was pre-filled from the rotation.
        $slugs = $this->slugsFor('2026-08-05');

        $this->assertContains('waakye', $slugs);
        $this->assertContains('plantain-etor', $slugs);
        $this->assertNotContains('gari-fotor', $slugs);
    }

    /**
     * ⚠️ THE FEATURE. Ticking a cell puts a dish on a day the template never mentioned, and
     * nothing about that dish's usual days changes.
     */
    public function test_ticking_a_cell_adds_a_dish_to_a_day_it_is_not_usually_cooked_on(): void
    {
        $thursday = $this->dayOn('2026-08-06');
        $gariFotor = MenuItem::query()->where('slug', 'gari-fotor')->firstOrFail(); // Fridays

        $this->assertNotContains('gari-fotor', $this->slugsFor('2026-08-06'));

        $thursday->items()->where('menu_item_id', $gariFotor->id)->update(['is_available' => true]);

        $this->assertContains('gari-fotor', $this->slugsFor('2026-08-06'));

        // The template is untouched — this was a one-week decision.
        $this->assertSame([5], $gariFotor->fresh()->default_service_weekdays);
    }

    public function test_unticking_a_cell_removes_a_dish_from_one_day_only(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail(); // Mon + Wed

        $this->dayOn('2026-08-05')->items()
            ->where('menu_item_id', $waakye->id)
            ->update(['is_available' => false]);

        $this->assertNotContains('waakye', $this->slugsFor('2026-08-05')); // Wed
        $this->assertContains('waakye', $this->slugsFor('2026-08-10'));    // the Monday
    }

    /**
     * ⚠️ "NO CYCLE" AND "NOTHING ON THE MENU" ARE DIFFERENT ANSWERS.
     *
     * Both are an empty list. Only one means she isn't cooking. Collapsing them is the
     * plausible-empty-result failure the brief names in §2.1 — the storefront needs to say
     * "no menu planned yet", not "nothing on the rotation today".
     */
    public function test_a_date_outside_every_cycle_says_so(): void
    {
        $this->getJson('/api/v1/menu/day/2027-01-04')
            ->assertOk()
            ->assertJsonPath('data.has_cycle', false)
            ->assertJsonPath('data.meals', [])
            ->assertJsonPath('data.ordering', null);
    }

    public function test_a_covered_date_reports_has_cycle_true(): void
    {
        $this->getJson('/api/v1/menu/day/2026-08-05')
            ->assertOk()
            ->assertJsonPath('data.has_cycle', true);
    }

    /** A draft is invisible, so its dates read as uncovered rather than as an empty menu. */
    public function test_a_draft_cycle_is_not_visible_to_the_storefront(): void
    {
        $this->cycle->update(['status' => CycleStatus::Draft->value]);

        $this->getJson('/api/v1/menu/day/2026-08-05')
            ->assertOk()
            ->assertJsonPath('data.has_cycle', false);
    }

    /**
     * The verdict travels with the menu, so the page can explain a closed day without a
     * second round trip.
     */
    public function test_the_ordering_verdict_comes_back_with_the_menu(): void
    {
        $this->getJson('/api/v1/menu/day/2026-08-05')
            ->assertOk()
            ->assertJsonStructure(['data' => ['ordering' => ['status', 'reason', 'message']]]);
    }

    public function test_a_closed_day_still_lists_its_menu_but_reports_closed(): void
    {
        $this->dayOn('2026-08-05')->update(['is_open' => false]);

        $response = $this->getJson('/api/v1/menu/day/2026-08-05')->assertOk();

        // She can still SEE what would have been cooked — the day is shut, not erased.
        $this->assertNotEmpty($response->json('data.meals'));
        $this->assertSame('closed', $response->json('data.ordering.status'));
        $this->assertSame('day_closed', $response->json('data.ordering.reason'));
    }

    /** A dish retired after the matrix was filled in simply disappears — never a null. */
    public function test_a_retired_dish_drops_out_of_the_matrix_result(): void
    {
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        $waakye->update(['is_active' => false]);

        $slugs = $this->slugsFor('2026-08-05');

        $this->assertNotContains('waakye', $slugs);
        $this->assertNotContains(null, $slugs);
    }

    public function test_pantry_is_unaffected_by_cycles(): void
    {
        // Shelf-stable goods belong to no cooking day and ship nationwide whenever.
        $slugs = array_column($this->getJson('/api/v1/menu/pantry')->assertOk()->json('data'), 'slug');

        $this->assertContains('jollof-base', $slugs);
    }
}
