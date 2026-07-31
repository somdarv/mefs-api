<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MenuCategory;
use App\Http\Resources\MenuItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\MenuItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The storefront's read side. Replaces the fixture data behind
 * ../mefs/src/lib/api/services/menu.service.ts — the only file on that side that changes.
 */
final class MenuController extends Controller
{
    /**
     * What the kitchen cooks on a date.
     *
     * ⚠️ Currently resolved from each dish's ROTATION TEMPLATE. In M3 this reads
     * `cycle_day_items` instead, so she can put Waakye on a Thursday without editing the
     * template. The response shape does not change when that happens — only the source.
     */
    public function day(string $date): JsonResponse
    {
        $day = $this->parseDate($date);

        $meals = MenuItem::query()
            ->active()
            ->ofCategory(MenuCategory::Meal)
            ->cookedOnWeekday($day->dayOfWeekIso)
            ->with(['options', 'addOns'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'date' => $day->toDateString(),
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
     * Dates are parsed strictly, in UTC.
     *
     * Accra is UTC+0 year-round with no DST, so server time is kitchen time and plain UTC
     * arithmetic is correct — a deliberate simplification of ONE market, matching
     * ../mefs/src/lib/preorder/service-days.ts. A second market needs a real timezone
     * library on both sides.
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
