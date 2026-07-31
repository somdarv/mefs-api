<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MenuItem;
use App\Models\MenuOption;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ── Public reads ──────────────────────────────────────────────────────────

    /**
     * ⚠️ THE SHAPE IS A CONTRACT.
     *
     * This must match `MenuItem` in ../mefs/src/types/menu.ts field for field. A missing
     * field does not error on the frontend — it arrives as `undefined` and renders an empty
     * card, which is the "plausible empty result" failure the brief warns about throughout.
     */
    public function test_the_day_menu_matches_the_frontend_menu_item_type(): void
    {
        // 2026-08-05 is a Wednesday (ISO 3) — Waakye and Etor.
        $this->getJson('/api/v1/menu/day/2026-08-05')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-08-05')
            ->assertJsonStructure([
                'data' => [
                    'date',
                    'meals' => [[
                        'id', 'slug', 'name', 'description', 'image_url', 'category',
                        'service_days',
                        'options' => [['id', 'option_key', 'label', 'size_label', 'variant_key', 'price']],
                        'add_ons',
                    ]],
                ],
            ]);
    }

    public function test_the_day_menu_only_returns_dishes_cooked_that_weekday(): void
    {
        // Wednesday = ISO 3.
        $slugs = collect($this->getJson('/api/v1/menu/day/2026-08-05')->json('data.meals'))
            ->pluck('slug');

        $this->assertContains('waakye', $slugs);         // service_days [1, 3]
        $this->assertContains('plantain-etor', $slugs);  // service_days [3]
        $this->assertNotContains('gari-fotor', $slugs);  // service_days [5]
    }

    public function test_a_day_with_nothing_on_the_rotation_returns_an_empty_list_not_an_error(): void
    {
        // 2026-08-08 is a Saturday. She cooks Mon-Fri, so there is genuinely nothing —
        // and "nothing" is a valid answer the storefront renders a message for.
        $this->getJson('/api/v1/menu/day/2026-08-08')
            ->assertOk()
            ->assertJsonPath('data.meals', []);
    }

    public function test_pantry_returns_only_shelf_stable_goods(): void
    {
        $items = $this->getJson('/api/v1/menu/pantry')->assertOk()->json('data');

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertSame('pantry', $item['category']);
            // Shelf-stable goods belong to no rotation slot — they sell whenever.
            $this->assertSame([], $item['service_days']);
        }
    }

    public function test_meals_never_appear_in_the_pantry(): void
    {
        $slugs = collect($this->getJson('/api/v1/menu/pantry')->json('data'))->pluck('slug');

        $this->assertNotContains('waakye', $slugs);
        $this->assertContains('jollof-base', $slugs);
    }

    public function test_prices_are_integer_minor_units(): void
    {
        $waakye = collect($this->getJson('/api/v1/menu/day/2026-08-05')->json('data.meals'))
            ->firstWhere('slug', 'waakye');

        // GHS 40.00 crosses the wire as 4000, never 40 and never "40.00".
        $this->assertSame(4000, $waakye['options'][0]['price']);
        $this->assertIsInt($waakye['options'][0]['price']);
    }

    public function test_a_multi_option_dish_returns_every_option(): void
    {
        $base = collect($this->getJson('/api/v1/menu/pantry')->json('data'))
            ->firstWhere('slug', 'jollof-base');

        // Three sizes x two heat levels.
        $this->assertCount(6, $base['options']);
        $this->assertSame(['500ml', '500ml', '1L', '1L', '2.5L', '2.5L'], array_column($base['options'], 'size_label'));
    }

    public function test_add_ons_come_through_on_the_dish_that_has_them(): void
    {
        $etor = collect($this->getJson('/api/v1/menu/day/2026-08-05')->json('data.meals'))
            ->firstWhere('slug', 'plantain-etor');

        $this->assertCount(5, $etor['add_ons']);
        $this->assertContains('Avocado', array_column($etor['add_ons'], 'name'));
    }

    public function test_an_inactive_dish_is_hidden_from_the_storefront(): void
    {
        MenuItem::query()->where('slug', 'waakye')->update(['is_active' => false]);

        $slugs = collect($this->getJson('/api/v1/menu/day/2026-08-05')->json('data.meals'))
            ->pluck('slug');

        $this->assertNotContains('waakye', $slugs);
    }

    /**
     * `createFromFormat` with an exact format rather than `parse`, because `parse` accepts
     * "next tuesday" and a typo'd URL should say so rather than silently serve a different
     * day's menu to a customer who then turns up on the wrong date.
     */
    public function test_a_malformed_date_is_rejected_rather_than_guessed_at(): void
    {
        foreach (['not-a-date', '2026-13-01', '05-08-2026', '2026-8-5'] as $bad) {
            $this->getJson("/api/v1/menu/day/{$bad}")
                ->assertStatus(422)
                ->assertJsonPath('success', false);
        }
    }

    // ── The soft-delete trap ──────────────────────────────────────────────────

    /**
     * ⚠️ BRIEF TRAP §10.5.
     *
     * `UNIQUE(menu_item_id, option_key)` is enforced by Postgres and a soft-deleted row
     * STILL OCCUPIES ITS INDEX ENTRY. So retiring "500ml mild" and adding it back — an
     * entirely ordinary correction — fails with a constraint violation on a row the UI
     * says does not exist.
     */
    public function test_a_soft_deleted_option_can_be_re_added(): void
    {
        $item = MenuItem::query()->where('slug', 'jollof-base')->firstOrFail();

        $original = $item->options()->where('option_key', '500ml-mild')->firstOrFail();
        $original->delete();

        $this->assertSoftDeleted('menu_item_options', ['id' => $original->id]);

        // The naive path — MenuOption::create() — throws a unique violation here.
        $reinstated = MenuOption::reinstate($item, '500ml-mild', [
            'menu_item_id' => $item->id,
            'option_key' => '500ml-mild',
            'label' => '500ml mild',
            'size_label' => '500ml',
            'variant_key' => 'mild',
            'price' => 6500,
        ]);

        $this->assertNotSoftDeleted('menu_item_options', ['id' => $reinstated->id]);
        $this->assertSame(6500, $reinstated->price);
    }

    /**
     * Restoring rather than duplicating is not just about dodging the error. The option id
     * is load-bearing — order lines point at it — so a NEW row would orphan every
     * historical line that referenced the old one.
     */
    public function test_re_adding_restores_the_same_row_rather_than_creating_a_second(): void
    {
        $item = MenuItem::query()->where('slug', 'jollof-base')->firstOrFail();
        $original = $item->options()->where('option_key', '500ml-mild')->firstOrFail();

        $original->delete();

        $reinstated = MenuOption::reinstate($item, '500ml-mild', [
            'menu_item_id' => $item->id,
            'option_key' => '500ml-mild',
            'label' => '500ml mild',
            'price' => 6000,
        ]);

        $this->assertSame(
            $original->id,
            $reinstated->id,
            'A new row was created. Every historical order line pointing at the old id is '.
            'now orphaned.',
        );
    }

    // ── The one-dish-one-row rule ─────────────────────────────────────────────

    /**
     * Brief §3.3 — the single most important modelling decision in the system. One row per
     * dish, `UNIQUE(slug)`, branch service on the pivot. The wrong model silently stopped
     * recipe deduction entirely when a second branch opened.
     */
    public function test_a_dish_is_one_row_served_to_branches_through_the_pivot(): void
    {
        $this->assertSame(1, MenuItem::query()->where('slug', 'waakye')->count());

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->assertGreaterThan(0, $item->branches()->count());
        $this->assertTrue((bool) $item->branches()->first()->pivot->is_available);
    }
}
