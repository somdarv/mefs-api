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

    /** One row per date, each pre-filled from the weekly rotation. */
    private function fillDays(OrderCycle $cycle): void
    {
        // Meals only. Pantry goods are shelf-stable and belong to no cooking day — they
        // ship nationwide whenever, which is why they carry an empty rotation.
        $meals = MenuItem::query()
            ->active()
            ->ofCategory(MenuCategory::Meal)
            ->orderBy('position')
            ->get();

        foreach ($cycle->serviceDates() as $date) {
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
