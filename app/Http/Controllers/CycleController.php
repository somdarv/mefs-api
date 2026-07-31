<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\CycleDay;
use App\Models\OrderCycle;
use App\Services\Ordering\CycleGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * What the storefront needs to draw its date picker: which days exist, and for each one
 * whether you may order and why not.
 *
 * The gate runs per day HERE rather than being re-implemented in TypeScript. The client
 * explains the verdict; it never reaches one.
 */
final class CycleController extends Controller
{
    public function __construct(private readonly CycleGate $gate) {}

    /**
     * The cycle a customer is currently looking at.
     *
     * "Current" is the earliest visible cycle whose cooking window has not finished — not
     * the one whose ordering window is open, because a customer arriving between cycles
     * should see what is coming rather than an empty page.
     */
    public function current(): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');

        $cycle = OrderCycle::query()
            ->visible()
            ->where('service_end_date', '>=', $now->toDateString())
            ->orderBy('service_start_date')
            ->with('days.items')
            ->first();

        if ($cycle === null) {
            // A real, honest answer: there is no cycle. Distinct from an empty day list,
            // which would read as "she has nothing on the menu".
            return ApiResponse::success([
                'cycle' => null,
                'days' => [],
            ], 'No cycle is open');
        }

        return ApiResponse::success([
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'note' => $cycle->note,
                'service_start_date' => $cycle->service_start_date->toDateString(),
                'service_end_date' => $cycle->service_end_date->toDateString(),
                'orders_open_at' => $cycle->orders_open_at->toIso8601String(),
                'orders_close_at' => $cycle->orders_close_at->toIso8601String(),
            ],
            'days' => $cycle->days->map(fn (CycleDay $day) => $this->dayPayload($cycle, $day, $now))->all(),
        ], $cycle->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function dayPayload(OrderCycle $cycle, CycleDay $day, CarbonImmutable $now): array
    {
        $state = $this->gate->check($cycle, $day, $now);

        return [
            // ⚠️ The basket keys on THIS, not on the date. A checkout session binds to a
            // cycle day id, and a date string cannot identify a day across two cycles — nor
            // can the server accept one without re-resolving it, which is a lookup the
            // client would then be able to get wrong.
            'id' => $day->id,
            'date' => $day->date->toDateString(),
            'weekday' => $day->date->dayOfWeekIso,
            'short_label' => $day->date->format('D'),
            'long_label' => $day->date->format('l'),
            'day_of_month' => $day->date->day,

            // The whole verdict, not a boolean — so the UI can say WHY a day is unavailable
            // rather than just greying it out. `kitchen_note` is deliberately absent: it is
            // staff-only and this is a public endpoint.
            'ordering' => $state->toArray(),

            // Which dishes are on that day, for the storefront's menu list.
            'menu_item_ids' => $day->items
                ->where('is_available', true)
                ->pluck('menu_item_id')
                ->values()
                ->all(),
        ];
    }
}
