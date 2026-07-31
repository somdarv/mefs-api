<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Enums\CycleStatus;
use App\Models\CycleDay;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Services\Ordering\CycleBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CycleBuilderTest extends TestCase
{
    use RefreshDatabase;

    private CycleBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->builder = app(CycleBuilder::class);
    }

    private function make(array $overrides = []): OrderCycle
    {
        return $this->builder->create(array_merge([
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ], $overrides));
    }

    public function test_it_creates_one_day_per_date_inclusive_of_both_ends(): void
    {
        $cycle = $this->make();

        // 5 to 12 August inclusive is eight days, not seven.
        $this->assertCount(8, $cycle->days);
        $this->assertSame('2026-08-05', $cycle->days->first()->date->toDateString());
        $this->assertSame('2026-08-12', $cycle->days->last()->date->toDateString());
    }

    public function test_a_new_cycle_starts_as_a_draft(): void
    {
        $this->assertSame(CycleStatus::Draft, $this->make()->status);
    }

    /**
     * The matrix is pre-filled from each dish's weekly rotation. She sets one of these up
     * ~52 times a year; a blank grid to tick by hand every week is the difference between a
     * tool she uses and one she abandons.
     */
    public function test_the_matrix_is_prefilled_from_the_weekly_rotation(): void
    {
        $cycle = $this->make();

        $wednesday = $cycle->days->first(fn (CycleDay $d) => $d->date->dayOfWeekIso === 3);
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();   // days [1, 3]
        $gariFotor = MenuItem::query()->where('slug', 'gari-fotor')->firstOrFail(); // days [5]

        $this->assertTrue($wednesday->items->firstWhere('menu_item_id', $waakye->id)->is_available);
        $this->assertFalse($wednesday->items->firstWhere('menu_item_id', $gariFotor->id)->is_available);
    }

    /**
     * EVERY dish gets a cell, available or not. A missing row would mean she can only ever
     * remove dishes from a day, never add one that isn't on the template — and "Waakye on a
     * Thursday because someone asked" is the whole point of the matrix.
     */
    public function test_every_meal_gets_a_cell_on_every_day_even_when_off_rotation(): void
    {
        $cycle = $this->make();
        $mealCount = MenuItem::query()->active()->where('category', 'meal')->count();

        foreach ($cycle->days as $day) {
            $this->assertCount($mealCount, $day->items, "Day {$day->date->toDateString()} is missing cells.");
        }
    }

    /** Pantry goods are shelf-stable and belong to no cooking day. */
    public function test_pantry_goods_are_not_in_the_matrix(): void
    {
        $cycle = $this->make();
        $jollofBase = MenuItem::query()->where('slug', 'jollof-base')->firstOrFail();

        $this->assertNull($cycle->days->first()->items->firstWhere('menu_item_id', $jollofBase->id));
    }

    // ── The overlap constraint ────────────────────────────────────────────────

    /**
     * ⚠️ WITHOUT THIS, "which cycle owns 6 August?" HAS TWO ANSWERS.
     *
     * Every query resolving a date to a cycle would then pick whichever row came back
     * first, and the bug surfaces as one customer's order landing in last week's plan.
     *
     * Enforced by a Postgres EXCLUSION constraint rather than a SELECT-then-INSERT check,
     * because the latter passes for both of two admins saving at the same moment.
     */
    public function test_two_cycles_cannot_cover_the_same_date(): void
    {
        $this->make();

        $this->expectException(QueryException::class);

        $this->make([
            'service_start_date' => '2026-08-10',   // overlaps 10-12 Aug
            'service_end_date' => '2026-08-17',
            'orders_open_at' => '2026-08-06T00:00:00Z',
            'orders_close_at' => '2026-08-09T18:00:00Z',
        ]);
    }

    public function test_adjacent_cycles_are_fine(): void
    {
        $this->make();

        $next = $this->make([
            'service_start_date' => '2026-08-13',   // starts the day after
            'service_end_date' => '2026-08-19',
            'orders_open_at' => '2026-08-08T00:00:00Z',
            'orders_close_at' => '2026-08-12T18:00:00Z',
        ]);

        $this->assertNotNull($next->id);
    }

    /** History is allowed to overlap the present. */
    public function test_an_archived_cycle_does_not_block_a_new_one(): void
    {
        $old = $this->make();
        $old->update(['status' => CycleStatus::Archived->value]);

        $this->assertNotNull($this->make(['orders_open_at' => '2026-08-01T01:00:00Z'])->id);
    }

    public function test_a_cycle_ending_before_it_starts_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->make(['service_start_date' => '2026-08-12', 'service_end_date' => '2026-08-05']);
    }

    public function test_an_ordering_window_that_closes_before_it_opens_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->make([
            'orders_open_at' => '2026-08-04T18:00:00Z',
            'orders_close_at' => '2026-08-01T00:00:00Z',
        ]);
    }

    // ── Cloning ───────────────────────────────────────────────────────────────

    /**
     * The default offset is the cycle's own LENGTH, so the copy starts the day after the
     * source ends. A hardcoded 7 would be wrong for the 8-day window she actually described
     * (5-12 August) — and would collide with the source on the overlap constraint.
     */
    public function test_cloning_defaults_to_starting_the_day_after_the_source_ends(): void
    {
        $source = $this->make();   // 5-12 Aug inclusive: eight days

        $clone = $this->builder->cloneFrom($source);

        $this->assertSame('2026-08-13', $clone->service_start_date->toDateString());
        $this->assertSame('2026-08-20', $clone->service_end_date->toDateString());
        // The ordering window shifts by the same amount, keeping its lead time.
        $this->assertSame('2026-08-09T00:00:00+00:00', $clone->orders_open_at->toIso8601String());
        $this->assertSame('2026-08-12T18:00:00+00:00', $clone->orders_close_at->toIso8601String());
    }

    public function test_an_explicit_offset_is_honoured(): void
    {
        $source = $this->make();

        $clone = $this->builder->cloneFrom($source, 14);

        $this->assertSame('2026-08-19', $clone->service_start_date->toDateString());
    }

    /**
     * A 7-day offset must land Monday's dishes back on a Monday — otherwise "same as last
     * week" produces a menu nobody asked for.
     */
    public function test_cloning_by_a_week_preserves_the_day_of_week_alignment(): void
    {
        // A true 7-day week here, so a +7 offset is a whole number of weeks.
        $source = $this->make(['service_start_date' => '2026-08-05', 'service_end_date' => '2026-08-11']);
        $clone = $this->builder->cloneFrom($source, 7);

        foreach ($clone->days as $day) {
            $matching = $source->days->first(
                fn (CycleDay $d) => $d->date->dayOfWeekIso === $day->date->dayOfWeekIso,
            );

            $this->assertSame(
                $matching->items->where('is_available', true)->pluck('menu_item_id')->sort()->values()->all(),
                $day->items->where('is_available', true)->pluck('menu_item_id')->sort()->values()->all(),
            );
        }
    }

    /**
     * The matrix is copied, not re-derived from the template — otherwise her edits ("Waakye
     * on Thursday because someone asked") are lost, and cloning is no better than creating.
     */
    public function test_cloning_carries_her_edits_rather_than_re_deriving_the_template(): void
    {
        $source = $this->make();
        $thursday = $source->days->first(fn (CycleDay $d) => $d->date->dayOfWeekIso === 4);
        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();   // template says Mon/Wed

        $thursday->items->firstWhere('menu_item_id', $waakye->id)->update(['is_available' => true]);

        $clone = $this->builder->cloneFrom($source->fresh(['days.items']));
        $clonedThursday = $clone->days->first(fn (CycleDay $d) => $d->date->dayOfWeekIso === 4);

        $this->assertTrue(
            $clonedThursday->items->firstWhere('menu_item_id', $waakye->id)->is_available,
            'The clone re-derived from the weekly template and lost her edit.',
        );
    }

    public function test_a_clone_starts_fresh_with_nothing_sold(): void
    {
        $source = $this->make();
        $source->days->first()->items->first()->update(['portion_capacity' => 20, 'portions_sold' => 15]);

        $clone = $this->builder->cloneFrom($source->fresh(['days.items']));

        $this->assertSame(20, $clone->days->first()->items->first()->portion_capacity);
        $this->assertSame(0, $clone->days->first()->items->first()->portions_sold);
    }

    /**
     * "Travelling, no cooking this week" carried onto next week's cycle would be a lie the
     * customer reads on the storefront.
     */
    public function test_a_clone_does_not_inherit_the_customer_visible_note(): void
    {
        $source = $this->make();
        $source->update(['note' => 'Travelling, limited menu']);

        $this->assertNull($this->builder->cloneFrom($source->fresh())->note);
    }

    public function test_a_clone_starts_as_a_draft(): void
    {
        $source = $this->make();
        $source->update(['status' => CycleStatus::Published->value]);

        $this->assertSame(CycleStatus::Draft, $this->builder->cloneFrom($source->fresh())->status);
    }
}
