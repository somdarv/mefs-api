<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CycleDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CycleDay
 */
final class CycleDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'weekday' => $this->date->dayOfWeekIso,      // ISO: Monday = 1
            'short_label' => $this->date->format('D'),
            'long_label' => $this->date->format('l'),
            'day_of_month' => $this->date->day,

            'is_open' => $this->is_open,
            'cutoff_at' => $this->cutoff_at?->toIso8601String(),
            'capacity' => $this->capacity,

            // Staff-only — this resource is used by the ADMIN surface only. The customer
            // gets PublicCycleResource, which has no kitchen note at all.
            'kitchen_note' => $this->kitchen_note,

            'items' => CycleDayItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
