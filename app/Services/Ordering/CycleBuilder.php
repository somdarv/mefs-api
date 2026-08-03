<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\CycleStatus;
use App\Enums\MenuCategory;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a cycle and fills in its day grid and dish matrix.
 *
 * Pre-filling matters more than it sounds: she sets one of these up roughly 52 times a
 * year, and a blank 7x12 grid to tick by hand every week is the difference between a tool
 * she uses and a tool she abandons. The weekly rotation on each dish is the default, and
 * she edits from there.
 */
final class CycleBuilder
{
    /**
     * @param  array{name?: string, service_start_date: string, service_end_date: string,
     *               orders_open_at: string, orders_close_at: string, order_capacity?: int|null,
     *               note?: string|null}  $data
     */
    public function create(array $data, ?User $actor = null): OrderCycle
    {
        return DB::transaction(function () use ($data, $actor): OrderCycle {
            $start = CarbonImmutable::parse($data['service_start_date'], 'UTC')->startOfDay();
            $end = CarbonImmutable::parse($data['service_end_date'], 'UTC')->startOfDay();

            $name = $data['name'] ?? 'Week of '.$start->format('j M');

            $cycle = OrderCycle::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, $start),
                'service_start_date' => $start->toDateString(),
                'service_end_date' => $end->toDateString(),
                'orders_open_at' => CarbonImmutable::parse($data['orders_open_at'], 'UTC'),
                'orders_close_at' => CarbonImmutable::parse($data['orders_close_at'], 'UTC'),
                'status' => CycleStatus::Draft->value,
                'order_capacity' => $data['order_capacity'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->fillDays($cycle);

            return $cycle->load('days.items');
        });
    }

    /**
     * Copy an existing cycle forward. "Same as last week, shifted" is the most common thing
     * she will ever do here.
     *
     * `$offsetDays` defaults to the cycle's own LENGTH, so the copy begins the day after the
     * source ends. A fixed 7 is wrong for any cycle that is not exactly a week — her example
     * runs 5-12 August, which is eight days, and +7 would land the copy on the 12th and
     * collide with the source on the overlap constraint.
     *
     * ⚠️ An offset that still overlaps a live cycle throws. That is the exclusion constraint
     * doing its job (there can only be one answer to "which cycle owns this date"); the
     * controller turns it into a 422 rather than letting it surface as a 500.
     *
     * Day-of-week alignment only holds when the offset is a multiple of seven. For a 7-day
     * cycle the default gives exactly that, which is the case that matters.
     */
    public function cloneFrom(OrderCycle $source, ?int $offsetDays = null, ?User $actor = null): OrderCycle
    {
        $offsetDays ??= $source->service_start_date->diffInDays($source->service_end_date) + 1;

        return DB::transaction(function () use ($source, $offsetDays, $actor): OrderCycle {
            $start = $source->service_start_date->addDays($offsetDays);
            $end = $source->service_end_date->addDays($offsetDays);
            $name = 'Week of '.$start->format('j M');

            $cycle = OrderCycle::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, $start),
                'service_start_date' => $start->toDateString(),
                'service_end_date' => $end->toDateString(),
                'orders_open_at' => $source->orders_open_at->addDays($offsetDays),
                'orders_close_at' => $source->orders_close_at->addDays($offsetDays),
                'status' => CycleStatus::Draft->value,
                'order_capacity' => $source->order_capacity,
                // The note is NOT copied. "Travelling, no cooking this week" carried
                // forward would be a lie on the new cycle, and a lie the customer reads.
                'note' => null,
                'created_by' => $actor?->id,
            ]);

            // Copy the matrix rather than re-deriving it from the template, so her edits
            // ("Waakye on Thursday because someone asked") survive the clone. That is the
            // whole reason to clone instead of create.
            foreach ($source->days()->with('items')->get() as $sourceDay) {
                $day = CycleDay::query()->create([
                    'order_cycle_id' => $cycle->id,
                    'date' => $sourceDay->date->addDays($offsetDays)->toDateString(),
                    'is_open' => $sourceDay->is_open,
                    'cutoff_at' => $sourceDay->cutoff_at?->addDays($offsetDays),
                    'capacity' => $sourceDay->capacity,
                    'kitchen_note' => null,   // as with the cycle note
                ]);

                foreach ($sourceDay->items as $item) {
                    CycleDayItem::query()->create([
                        'cycle_day_id' => $day->id,
                        'menu_item_id' => $item->menu_item_id,
                        'is_available' => $item->is_available,
                        'portion_capacity' => $item->portion_capacity,
                        'portions_sold' => 0,      // a fresh week has sold nothing
                        'position' => $item->position,
                    ]);
                }
            }

            return $cycle->load('days.items');
        });
    }

    /**
     * Move a DRAFT cycle's cooking window, keeping the days that survive the move.
     *
     * ── WHY THIS IS DRAFT-ONLY, AND WHY IT EXISTS AT ALL ──────────────────────
     *
     * `update()` refuses to touch the service window, for a good reason: changing which
     * dates a cycle covers means adding and removing day rows, and dropping a day that
     * orders point at would orphan them. That reason is entirely about cycles customers can
     * reach. A draft is invisible to `CycleGate` whatever its dates say, so no order can
     * exist against one — and "I typed the wrong week" is otherwise only fixable by deleting
     * the cycle and rebuilding the matrix by hand.
     *
     * So the guard stays exactly where it was for anything published, and drafts get to be
     * edited like the unfinished things they are. The caller enforces that; this method
     * asserts it too rather than trusting it, because the cost of being wrong is orphaned
     * order lines.
     *
     * ── DATES THAT SURVIVE ARE NOT REBUILT ────────────────────────────────────
     *
     * Shifting 9–14 Aug to 10–15 Aug keeps five of the six days. Deleting and re-creating
     * the lot would be less code and would silently discard every matrix edit she has made
     * to those five — which is the work the screen exists to capture. Only the dates that
     * genuinely leave are deleted, and only the dates that genuinely arrive are built.
     */
    public function reshape(OrderCycle $cycle, string $startDate, string $endDate): OrderCycle
    {
        if ($cycle->status !== CycleStatus::Draft) {
            throw new \LogicException('Only a draft cycle may have its service window reshaped.');
        }

        return DB::transaction(function () use ($cycle, $startDate, $endDate): OrderCycle {
            $start = CarbonImmutable::parse($startDate, 'UTC')->startOfDay();
            $end = CarbonImmutable::parse($endDate, 'UTC')->startOfDay();

            $cycle->update([
                'service_start_date' => $start->toDateString(),
                'service_end_date' => $end->toDateString(),
            ]);

            // Refreshed so `serviceDates()` reads the window we just wrote, not the one the
            // instance was loaded with.
            $cycle->refresh();

            $wanted = $cycle->serviceDates();
            $existing = $cycle->days()->get()->keyBy(fn (CycleDay $day) => $day->date->toDateString());

            // Gone: dates no longer in the window. Items go with them via the FK cascade.
            foreach ($existing as $date => $day) {
                if (! in_array($date, $wanted, true)) {
                    $day->delete();
                }
            }

            // Arrived: dates the window did not previously cover.
            $this->fillDays($cycle, array_values(array_filter(
                $wanted,
                fn (string $date) => ! $existing->has($date),
            )));

            return $cycle->fresh(['days.items']);
        });
    }

    /**
     * One row per date, each pre-filled from the weekly rotation.
     *
     * `$dates` narrows it to a subset, which is what `reshape()` needs — creating a row for
     * a date that already has one would collide on the unique index and, worse, would mean
     * re-deriving a matrix she has already edited.
     *
     * @param  list<string>|null  $dates  Null for the cycle's whole window.
     */
    private function fillDays(OrderCycle $cycle, ?array $dates = null): void
    {
        // Meals only. Pantry goods are shelf-stable and belong to no cooking day — they
        // ship nationwide whenever, which is why they carry an empty rotation.
        $meals = MenuItem::query()
            ->active()
            ->ofCategory(MenuCategory::Meal)
            ->orderBy('position')
            ->get();

        foreach ($dates ?? $cycle->serviceDates() as $date) {
            $weekday = CarbonImmutable::parse($date, 'UTC')->dayOfWeekIso;

            $day = CycleDay::query()->create([
                'order_cycle_id' => $cycle->id,
                'date' => $date,
                'is_open' => true,
            ]);

            $position = 0;

            foreach ($meals as $meal) {
                $onRotation = in_array($weekday, $meal->default_service_weekdays, true);

                // EVERY dish gets a row, available or not. The matrix needs a cell to
                // toggle — a missing row would mean she can only ever remove dishes from a
                // day, never add one that isn't on the template.
                CycleDayItem::query()->create([
                    'cycle_day_id' => $day->id,
                    'menu_item_id' => $meal->id,
                    'is_available' => $onRotation,
                    'position' => $position++,
                ]);
            }
        }
    }

    private function uniqueSlug(string $name, CarbonImmutable $start): string
    {
        $base = Str::slug($name.'-'.$start->format('Y-m-d'));
        $slug = $base;
        $suffix = 2;

        while (OrderCycle::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
