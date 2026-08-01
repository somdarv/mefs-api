<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CycleOverride;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\CycleResource;
use App\Http\Responses\ApiResponse;
use App\Models\CycleDay;
use App\Models\OrderCycle;
use App\Services\Audit\AuditLog;
use App\Services\Ordering\CycleBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The back office's cycle editor.
 *
 * ⚠️ `cycles.override` IS A SEPARATE PERMISSION from `cycles.manage`.
 *
 * Editing next week's plan and slamming the shop shut right now are different acts with
 * different blast radii. The second changes what the public can do this instant, and it is
 * always recorded with a reason — because the only question that matters a week later is
 * "why were we closed on the 6th?".
 */
final class CycleController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(
        private readonly CycleBuilder $builder,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesView);

        $cycles = OrderCycle::query()
            ->when(
                $request->boolean('include_archived') === false,
                fn ($q) => $q->where('status', '!=', CycleStatus::Archived->value),
            )
            ->orderByDesc('service_start_date')
            ->get();

        return ApiResponse::success(CycleResource::collection($cycles), 'Cycles');
    }

    public function show(Request $request, OrderCycle $cycle): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesView);

        return ApiResponse::success(
            new CycleResource($cycle->load(['days.items', 'overrideBy'])),
            $cycle->name,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);

        $data = $this->validateWindows($request);

        $cycle = $this->guardAgainstOverlap(
            fn () => $this->builder->create($data, $request->user()),
        );

        return ApiResponse::created(
            new CycleResource($cycle->load('days.items')),
            "{$cycle->name} created",
        );
    }

    public function update(Request $request, OrderCycle $cycle): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);

        abort_unless($cycle->status->isEditable(), 422, 'A completed or archived cycle cannot be edited.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'orders_open_at' => ['sometimes', 'date'],
            'orders_close_at' => ['sometimes', 'date'],
            'order_capacity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // The service window is deliberately NOT editable here. Changing which dates a
        // cycle covers means adding or removing day rows and their matrices, and doing that
        // to a cycle with orders against it would orphan them. Create a new cycle instead.
        $this->assertOrderingWindowOrdered(
            $data['orders_open_at'] ?? $cycle->orders_open_at->toIso8601String(),
            $data['orders_close_at'] ?? $cycle->orders_close_at->toIso8601String(),
        );

        $cycle->update($data);

        return ApiResponse::success(
            new CycleResource($cycle->fresh(['days.items'])),
            "{$cycle->name} updated",
        );
    }

    /** Draft → published. The moment customers can see it at all. */
    public function publish(Request $request, OrderCycle $cycle): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);

        // A cycle with no dish available on any day would publish an empty menu — which
        // reads to a customer as "she isn't cooking" rather than "this isn't set up".
        $hasAnything = $cycle->days()
            ->whereHas('items', fn ($q) => $q->where('is_available', true))
            ->exists();

        abort_unless($hasAnything, 422, 'Add at least one dish to one day before publishing.');

        $cycle->publish();

        return ApiResponse::success(new CycleResource($cycle->fresh(['days.items'])), "{$cycle->name} is live");
    }

    /**
     * THE SWITCH. Force open, force closed, or back to the calendar.
     *
     * `force_open` past the cutoff is the "reopen orders beyond the deadline" behaviour;
     * see CycleGate for where it sits in the precedence order.
     */
    public function override(Request $request, OrderCycle $cycle): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesOverride);

        $data = $request->validate([
            'override' => ['present', 'nullable', Rule::enum(CycleOverride::class)],
            // Required whenever an override is being SET. An override with no reason is
            // unreviewable later, and "why were we closed?" is the whole audit question.
            'reason' => ['nullable', 'required_with:override', 'string', 'max:200'],
        ]);

        $override = $data['override'] === null ? null : CycleOverride::from($data['override']);
        $was = $cycle->override?->value;

        $cycle->applyOverride($override, $data['reason'] ?? null, $request->user());

        /*
         * ⚠️ THE MOST AUDIT-WORTHY ACT IN THE APPLICATION.
         *
         * `force_open` beats the cutoff check — it is the one thing that lets the kitchen
         * take a job it had already said no to. "Why were we open on Friday night" and "who
         * closed Wednesday" are the questions this row exists to answer, which is why
         * `reason` is required whenever an override is set rather than cleared.
         */
        $this->audit->record(
            action: 'cycle.override',
            summary: match ($override) {
                CycleOverride::ForceOpen => "Forced orders open on {$cycle->name}",
                CycleOverride::ForceClosed => "Forced orders closed on {$cycle->name}",
                null => "Returned {$cycle->name} to its schedule",
            },
            actor: $request->user(),
            subject: $cycle,
            before: ['override' => $was],
            after: ['override' => $override?->value],
            reason: $data['reason'] ?? null,
        );

        return ApiResponse::success(
            new CycleResource($cycle->fresh(['days.items', 'overrideBy'])),
            match ($override) {
                CycleOverride::ForceOpen => 'Orders forced open',
                CycleOverride::ForceClosed => 'Orders forced closed',
                null => 'Back to the schedule',
            },
        );
    }

    /** "Same as last week." The most-used button in this whole surface. */
    public function clone(Request $request, OrderCycle $cycle): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);

        $data = $request->validate([
            // Null means "the day after this one ends", which is what she wants ~always.
            'offset_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $clone = $this->guardAgainstOverlap(
            fn () => $this->builder->cloneFrom($cycle, $data['offset_days'] ?? null, $request->user()),
        );

        return ApiResponse::created(
            new CycleResource($clone->load('days.items')),
            "{$clone->name} created from {$cycle->name}",
        );
    }

    /** One day: close it, cap it, give it its own cutoff. */
    public function updateDay(Request $request, OrderCycle $cycle, CycleDay $day): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);
        $this->assertDayBelongsTo($cycle, $day);

        $data = $request->validate([
            'is_open' => ['sometimes', 'boolean'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cutoff_at' => ['sometimes', 'nullable', 'date'],
            'kitchen_note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $day->update($data);

        return ApiResponse::success(
            new CycleResource($cycle->fresh(['days.items'])),
            $day->date->format('l j F').' updated',
        );
    }

    /**
     * THE DISH MATRIX for one day. Sent as a whole row rather than cell by cell, so a
     * reorder plus three toggles is one request and one atomic result.
     */
    public function updateDayItems(Request $request, OrderCycle $cycle, CycleDay $day): JsonResponse
    {
        $this->authorizePermission($request, Permission::CyclesManage);
        $this->assertDayBelongsTo($cycle, $day);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.is_available' => ['required', 'boolean'],
            'items.*.portion_capacity' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($day, $data): void {
            foreach ($data['items'] as $position => $item) {
                $day->items()->updateOrCreate(
                    ['menu_item_id' => $item['menu_item_id']],
                    [
                        'is_available' => $item['is_available'],
                        'portion_capacity' => $item['portion_capacity'] ?? null,
                        'position' => $position,
                    ],
                );
            }
        });

        return ApiResponse::success(
            new CycleResource($cycle->fresh(['days.items'])),
            'Menu updated for '.$day->date->format('l j F'),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateWindows(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'service_start_date' => ['required', 'date_format:Y-m-d'],
            'service_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:service_start_date'],
            'orders_open_at' => ['required', 'date'],
            'orders_close_at' => ['required', 'date', 'after:orders_open_at'],
            'order_capacity' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // An ordering window that ends after the cooking window ends is nonsense: she would
        // be taking orders for food she has already cooked and given away.
        if (strtotime($data['orders_close_at']) > strtotime($data['service_end_date'].' 23:59:59')) {
            throw ValidationException::withMessages([
                'orders_close_at' => ['Orders cannot close after the last cooking day.'],
            ]);
        }

        return $data;
    }

    private function assertOrderingWindowOrdered(string $opensAt, string $closesAt): void
    {
        if (strtotime($closesAt) <= strtotime($opensAt)) {
            throw ValidationException::withMessages([
                'orders_close_at' => ['Orders must close after they open.'],
            ]);
        }
    }

    private function assertDayBelongsTo(OrderCycle $cycle, CycleDay $day): void
    {
        // Nested route bindings are resolved independently, so without this a caller can
        // pass any day id under any cycle id and edit across cycles. Brief Law 2 in
        // miniature: never trust a client-supplied scope value.
        abort_unless($day->order_cycle_id === $cycle->id, 404, 'Not found.');
    }

    /**
     * Turn the exclusion-constraint violation into a 422 the UI can render.
     *
     * The constraint is the real guard — it holds under concurrency, which an application
     * check cannot — but a raw QueryException reaches the client as a 500 and tells her
     * nothing about what she did wrong.
     */
    private function guardAgainstOverlap(callable $work): OrderCycle
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'order_cycles_no_overlapping_service_window')) {
                throw ValidationException::withMessages([
                    'service_start_date' => [
                        'Those cooking dates overlap another cycle. Two cycles cannot cover the same day.',
                    ],
                ]);
            }

            throw $e;
        }
    }
}
