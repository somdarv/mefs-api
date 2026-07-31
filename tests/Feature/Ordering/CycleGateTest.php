<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Enums\CycleOverride;
use App\Enums\CycleStatus;
use App\Enums\OrderingStatus;
use App\Models\CycleDay;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Models\User;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\CycleGate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE GATE. Every rule she described, asserted.
 *
 * The scenario throughout is the one she gave: **taking orders 1-4 August, cooking 5-12
 * August**. Concrete dates rather than relative offsets, because "closes Tuesday 6pm" is
 * the kind of rule that breaks on a boundary and relative arithmetic in a test hides it.
 */
final class CycleGateTest extends TestCase
{
    use RefreshDatabase;

    private CycleGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->gate = app(CycleGate::class);
    }

    /** Orders 1-4 Aug (closing 18:00 on the 4th), cooking 5-12 Aug. */
    private function cycle(array $overrides = []): OrderCycle
    {
        $cycle = app(CycleBuilder::class)->create(array_merge([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ], $overrides));

        // The builder always creates a draft — publishing is a deliberate act, so the
        // default here is "published" only because most of these tests are about the
        // calendar rather than about publication.
        $cycle->update(['status' => $overrides['status'] ?? CycleStatus::Published->value]);

        return $cycle->fresh(['days.items']);
    }

    private function dayOf(OrderCycle $cycle, string $date): CycleDay
    {
        $day = $cycle->days->first(fn (CycleDay $d) => $d->date->toDateString() === $date);

        $this->assertNotNull($day, "The cycle has no day for {$date}.");

        return $day;
    }

    private function at(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso, 'UTC');
    }

    // ── The ordinary calendar ─────────────────────────────────────────────────

    public function test_orders_are_open_inside_the_window(): void
    {
        $cycle = $this->cycle();

        $state = $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(OrderingStatus::Open, $state->status);
        $this->assertSame('within_window', $state->reason);
        $this->assertTrue($state->allowsOrdering());
    }

    public function test_orders_are_not_yet_open_before_the_window_starts(): void
    {
        $cycle = $this->cycle();

        // 31 July — the window opens on 1 August.
        $state = $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-07-31T23:00:00Z'));

        $this->assertSame(OrderingStatus::NotYetOpen, $state->status);
        $this->assertSame('before_window', $state->reason);
        $this->assertFalse($state->allowsOrdering());

        // The customer is told WHEN, not just "no" — that is the whole reason this is a
        // separate state from `closed`.
        $this->assertNotNull($state->opensAt);
        $this->assertStringContainsString('Orders open', $state->message);
    }

    public function test_orders_close_once_the_cutoff_passes(): void
    {
        $cycle = $this->cycle();

        // 18:00 on the 4th is the cutoff. One second past it, ordering is over.
        $state = $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-04T18:00:01Z'));

        $this->assertSame(OrderingStatus::Closed, $state->status);
        $this->assertSame('cutoff_passed', $state->reason);
    }

    /** The boundary itself. `>=` — at exactly 18:00:00 it is shut, not open. */
    public function test_the_cutoff_instant_itself_is_closed(): void
    {
        $cycle = $this->cycle();

        $this->assertSame(
            OrderingStatus::Closed,
            $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-04T18:00:00Z'))->status,
        );

        $this->assertSame(
            OrderingStatus::Open,
            $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-04T17:59:59Z'))->status,
        );
    }

    public function test_a_draft_cycle_is_invisible_however_the_dates_read(): void
    {
        $cycle = $this->cycle(['status' => CycleStatus::Draft->value]);

        $state = $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(OrderingStatus::Closed, $state->status);
        $this->assertSame('unpublished', $state->reason);
    }

    // ── The manual switch ─────────────────────────────────────────────────────

    /**
     * ⚠️ THE FEATURE SHE ASKED FOR BY NAME: "reopen orders even beyond the estimated time".
     *
     * Someone rings at 9pm on the Monday after close. She says yes. force_open sits ABOVE
     * every clock check in the precedence order, which is exactly what makes this work.
     */
    public function test_force_open_reopens_ordering_after_the_cutoff_has_passed(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceOpen, 'Regular customer rang', null);

        // Four days past the cutoff, and the cooking window has already started.
        $state = $this->gate->check($cycle->fresh(), $this->dayOf($cycle, '2026-08-08'), $this->at('2026-08-08T21:00:00Z'));

        $this->assertSame(OrderingStatus::Open, $state->status);
        $this->assertSame('manual_reopen', $state->reason);
        $this->assertTrue($state->allowsOrdering());
    }

    public function test_force_open_also_opens_before_the_window_starts(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceOpen, 'Early bird', null);

        $state = $this->gate->check($cycle->fresh(), $this->dayOf($cycle, '2026-08-05'), $this->at('2026-07-20T09:00:00Z'));

        $this->assertSame(OrderingStatus::Open, $state->status);
    }

    public function test_force_closed_shuts_ordering_inside_the_window(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceClosed, 'Fully booked', null);

        $state = $this->gate->check($cycle->fresh(), $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(OrderingStatus::Closed, $state->status);
        $this->assertSame('manually_closed', $state->reason);
    }

    /**
     * The states are two cases of ONE column, so they cannot both be set — this asserts the
     * modelling holds rather than that some precedence rule between two booleans works.
     */
    public function test_the_override_is_one_value_so_the_two_forces_cannot_both_be_set(): void
    {
        $cycle = $this->cycle();

        $cycle->applyOverride(CycleOverride::ForceOpen, 'a', null);
        $cycle->applyOverride(CycleOverride::ForceClosed, 'b', null);

        $this->assertSame(CycleOverride::ForceClosed, $cycle->fresh()->override);
    }

    public function test_clearing_the_override_returns_to_the_calendar(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceClosed, 'Ill', null);
        $cycle->applyOverride(null, null, null);

        $state = $this->gate->check($cycle->fresh(), $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(OrderingStatus::Open, $state->status);
        $this->assertSame('within_window', $state->reason);
        $this->assertNull($cycle->fresh()->override_reason, 'A cleared override must not keep its reason.');
    }

    public function test_an_override_records_who_and_why(): void
    {
        $cycle = $this->cycle();
        $actor = User::factory()->admin()->create();

        $cycle->applyOverride(CycleOverride::ForceClosed, 'Power cut', $actor);

        $fresh = $cycle->fresh();
        $this->assertSame('Power cut', $fresh->override_reason);
        $this->assertSame($actor->id, $fresh->override_by);
        $this->assertNotNull($fresh->override_at);
    }

    // ── Precedence ────────────────────────────────────────────────────────────

    /** A stop always wins. It is the safe verdict when two instructions conflict. */
    public function test_a_closed_day_beats_force_open(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceOpen, 'Open the week back up', null);

        $day = $this->dayOf($cycle, '2026-08-06');
        $day->update(['is_open' => false]);

        $state = $this->gate->check($cycle->fresh(), $day->fresh(), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(
            'day_closed',
            $state->reason,
            'Reopening the week silently re-opened the day she cancelled for a funeral.',
        );
    }

    public function test_force_closed_beats_a_day_that_is_open(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceClosed, 'Ill', null);

        $state = $this->gate->check($cycle->fresh(), $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame('manually_closed', $state->reason);
    }

    // ── Per-day rules ─────────────────────────────────────────────────────────

    public function test_a_single_day_can_be_closed_without_touching_the_cycle(): void
    {
        $cycle = $this->cycle();

        $wednesday = $this->dayOf($cycle, '2026-08-05');
        $wednesday->update(['is_open' => false]);

        $this->assertSame(
            OrderingStatus::Closed,
            $this->gate->check($cycle, $wednesday->fresh(), $this->at('2026-08-02T10:00:00Z'))->status,
        );

        // The rest of the week carries on.
        $this->assertSame(
            OrderingStatus::Open,
            $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-06'), $this->at('2026-08-02T10:00:00Z'))->status,
        );
    }

    /** "Friday's food needs an extra day's notice" without splitting the week in two. */
    public function test_a_per_day_cutoff_overrides_the_cycle_close(): void
    {
        $cycle = $this->cycle();

        $day = $this->dayOf($cycle, '2026-08-07');
        $day->update(['cutoff_at' => $this->at('2026-08-03T12:00:00Z')]);

        // Past the day's own cutoff, still inside the cycle's.
        $this->assertSame(
            'cutoff_passed',
            $this->gate->check($cycle, $day->fresh(), $this->at('2026-08-04T09:00:00Z'))->reason,
        );

        // Another day in the same cycle is unaffected.
        $this->assertSame(
            OrderingStatus::Open,
            $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-04T09:00:00Z'))->status,
        );
    }

    // ── Capacity ──────────────────────────────────────────────────────────────

    public function test_a_full_day_is_sold_out_rather_than_closed(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle, '2026-08-05');
        $day->update(['capacity' => 10]);
        $day->setAttribute('orders_placed_count', 10);

        $state = $this->gate->check($cycle, $day, $this->at('2026-08-02T10:00:00Z'));

        // Distinct from closed on purpose: another day may still be available, and the
        // customer should be sent there rather than away.
        $this->assertSame(OrderingStatus::SoldOut, $state->status);
        $this->assertSame('day_capacity', $state->reason);
        $this->assertStringContainsString('another day', $state->message);
    }

    public function test_a_full_cycle_is_sold_out(): void
    {
        $cycle = $this->cycle(['order_capacity' => 50]);
        $cycle->setAttribute('orders_placed_count', 50);

        $state = $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame('cycle_capacity', $state->reason);
    }

    /** Capacity still applies under force_open — reopening is not a licence to oversell. */
    public function test_force_open_does_not_defeat_capacity(): void
    {
        $cycle = $this->cycle();
        $cycle->applyOverride(CycleOverride::ForceOpen, 'Squeeze one in', null);

        $day = $this->dayOf($cycle, '2026-08-05');
        $day->update(['capacity' => 5]);
        $day->setAttribute('orders_placed_count', 5);

        $state = $this->gate->check($cycle->fresh(), $day, $this->at('2026-08-08T21:00:00Z'));

        $this->assertSame(
            OrderingStatus::SoldOut,
            $state->status,
            'A manual reopen just let her oversell a day she has no room on.',
        );
    }

    public function test_remaining_reports_the_tightest_limit(): void
    {
        $cycle = $this->cycle(['order_capacity' => 40]);
        $cycle->setAttribute('orders_placed_count', 10);   // 30 left on the cycle

        $day = $this->dayOf($cycle, '2026-08-05');
        $day->update(['capacity' => 12]);
        $day->setAttribute('orders_placed_count', 4);      // 8 left on the day

        $state = $this->gate->check($cycle, $day, $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(8, $state->remaining);
    }

    public function test_remaining_is_null_when_nothing_is_capped(): void
    {
        $cycle = $this->cycle();

        $this->assertNull(
            $this->gate->check($cycle, $this->dayOf($cycle, '2026-08-05'), $this->at('2026-08-02T10:00:00Z'))->remaining,
        );
    }

    // ── Per-dish ──────────────────────────────────────────────────────────────

    public function test_a_dish_not_on_that_day_is_refused(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle, '2026-08-05')->load('items');   // Wednesday

        // Gari Fotor is a Friday dish, so its Wednesday cell is unavailable.
        $gariFotor = MenuItem::query()->where('slug', 'gari-fotor')->firstOrFail();

        $state = $this->gate->checkItem($cycle, $day, $gariFotor->id, 1, $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame('item_not_on_menu', $state->reason);
    }

    public function test_a_dish_on_that_day_is_allowed(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle, '2026-08-05')->load('items');

        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->assertTrue(
            $this->gate->checkItem($cycle, $day, $waakye->id, 1, $this->at('2026-08-02T10:00:00Z'))->allowsOrdering(),
        );
    }

    /** "Only 20 goat jollof on Thursday" — capacity per dish per day, without full IMS. */
    public function test_a_dish_sells_out_independently_of_the_day(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle, '2026-08-05')->load('items');

        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        $cell = $day->items->firstWhere('menu_item_id', $waakye->id);
        $cell->update(['portion_capacity' => 20, 'portions_sold' => 20]);

        $state = $this->gate->checkItem($cycle, $day->fresh('items'), $waakye->id, 1, $this->at('2026-08-02T10:00:00Z'));

        $this->assertSame(OrderingStatus::SoldOut, $state->status);
        $this->assertSame('item_capacity', $state->reason);

        // The DAY is still perfectly open — only that one dish is gone.
        $this->assertTrue(
            $this->gate->check($cycle, $day, $this->at('2026-08-02T10:00:00Z'))->allowsOrdering(),
        );
    }

    public function test_ordering_more_than_the_remaining_portions_is_refused(): void
    {
        $cycle = $this->cycle();
        $day = $this->dayOf($cycle, '2026-08-05')->load('items');

        $waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        $day->items->firstWhere('menu_item_id', $waakye->id)
            ->update(['portion_capacity' => 20, 'portions_sold' => 18]);

        $fresh = $day->fresh('items');

        $this->assertTrue($this->gate->checkItem($cycle, $fresh, $waakye->id, 2, $this->at('2026-08-02T10:00:00Z'))->allowsOrdering());

        $state = $this->gate->checkItem($cycle, $fresh, $waakye->id, 3, $this->at('2026-08-02T10:00:00Z'));
        $this->assertSame(OrderingStatus::SoldOut, $state->status);
        $this->assertStringContainsString('Only 2 left', $state->message);
    }
}
