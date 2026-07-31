<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MenuCategory;
use App\Http\Resources\MenuItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\CycleDay;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Services\Ordering\CycleGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The storefront's read side. Replaces the fixture data behind
 * ../mefs/src/lib/api/services/menu.service.ts.
 */
final class MenuController extends Controller
{
    public function __construct(private readonly CycleGate $gate) {}

    /**
     * What the kitchen cooks on a date.
     *
     * ⚠️ RESOLVED FROM THE CYCLE MATRIX, NOT THE WEEKDAY TEMPLATE.
     *
     * `menu_items.default_service_weekdays` only pre-fills a new cycle. The per-date truth is
     * `cycle_day_items`, which she edits freely — so putting Waakye on a Thursday shows up
     * here without her touching the template, and removing it from one Wednesday does not
     * remove it from every Wednesday.
     *
     * A date no cycle covers returns an empty menu **with `has_cycle: false`**. That
     * distinction is load-bearing: "nothing planned for this date" and "she isn't cooking
     * that day" look identical as an empty array, and the brief spends §2.1 and trap §10.4
     * on exactly this class of plausible-empty result.
     */
    public function day(string $date): JsonResponse
    {
        $day = $this->parseDate($date);

        $cycle = OrderCycle::query()
            ->visible()
            ->covering($day)
            ->with(['days' => fn ($q) => $q->whereDate('date', $day->toDateString())])
            ->first();

        $cycleDay = $cycle?->days->first();

        if ($cycle === null || $cycleDay === null) {
            return ApiResponse::success([
                'date' => $day->toDateString(),
                'has_cycle' => false,
                'ordering' => null,
                'meals' => [],
            ], 'No cycle covers '.$day->toDateString());
        }

        $meals = $this->mealsFor($cycleDay);

        return ApiResponse::success([
            'date' => $day->toDateString(),
            'has_cycle' => true,
            // The whole verdict travels with the menu, so the page can say why a day cannot
            // be ordered for without a second round trip.
            'ordering' => $this->gate->check($cycle, $cycleDay->loadMissing('items'))->toArray(),
            'meals' => MenuItemResource::collection($meals),
        ], 'Menu for '.$day->toDateString());
    }

    /** Shelf-stable goods. Tied to no cooking date — they ship nationwide whenever. */
    public function pantry(): JsonResponse
    {
        $items = MenuItem::query()
            ->active()
            ->ofCategory(MenuCategory::Pantry)
            ->with(['options', 'addOns'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(MenuItemResource::collection($items), 'Pantry');
    }

    /**
     * The dishes ticked for this day, in the order she arranged them.
     *
     * Ordered by the MATRIX position rather than the dish's own, because the matrix order is
     * the one she set for this particular day.
     */
    private function mealsFor(CycleDay $day): Collection
    {
        $cells = $day->loadMissing('items')->items
            ->where('is_available', true)
            ->sortBy('position');

        if ($cells->isEmpty()) {
            return collect();
        }

        $items = MenuItem::query()
            ->active()
            ->whereIn('id', $cells->pluck('menu_item_id'))
            ->with(['options', 'addOns'])
            ->get()
            ->keyBy('id');

        // `filter` because a dish retired since the matrix was filled in is simply gone —
        // an id with no row must not become a null in the list.
        return $cells
            ->map(fn ($cell) => $items->get($cell->menu_item_id))
            ->filter()
            ->values();
    }

    /**
     * Dates are parsed strictly, in UTC.
     *
     * Accra is UTC+0 year-round with no DST, so server time is kitchen time and plain UTC
     * arithmetic is correct — a deliberate simplification of ONE market. A second market
     * needs a real timezone library on both sides.
     *
     * `createFromFormat` with an exact format rather than `parse`, because `parse` accepts
     * "next tuesday" and a typo'd URL should 422, not silently serve a different day.
     */
    private function parseDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date, 'UTC');
        } catch (\Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([
                'date' => ['Expected a date as YYYY-MM-DD.'],
            ]);
        }

        return $parsed->startOfDay();
    }
}
