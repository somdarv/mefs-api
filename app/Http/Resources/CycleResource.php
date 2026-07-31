<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderCycle
 */
final class CycleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,

            // The two windows, kept as separate objects rather than four flat fields —
            // the whole point of the model is that these are two different things.
            'service_window' => [
                'start_date' => $this->service_start_date->toDateString(),
                'end_date' => $this->service_end_date->toDateString(),
                'day_count' => $this->service_start_date->diffInDays($this->service_end_date) + 1,
            ],
            'ordering_window' => [
                'opens_at' => $this->orders_open_at->toIso8601String(),
                'closes_at' => $this->orders_close_at->toIso8601String(),
            ],

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'override' => $this->override?->value,
            'override_reason' => $this->override_reason,
            'override_at' => $this->override_at?->toIso8601String(),
            'override_by' => $this->whenLoaded('overrideBy', fn () => $this->overrideBy?->name),

            'order_capacity' => $this->order_capacity,
            'note' => $this->note,
            'published_at' => $this->published_at?->toIso8601String(),

            'days' => CycleDayResource::collection($this->whenLoaded('days')),
        ];
    }
}
