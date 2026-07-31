<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CycleDayItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cell of the dish matrix.
 *
 * @mixin CycleDayItem
 */
final class CycleDayItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'menu_item_id' => $this->menu_item_id,
            'is_available' => $this->is_available,
            'portion_capacity' => $this->portion_capacity,
            'portions_sold' => $this->portions_sold,
            // Null when uncapped, 0 when sold out. The matrix renders those differently,
            // and conflating them would show "0 left" on every uncapped dish.
            'remaining' => $this->remaining(),
            'position' => $this->position,

            // Only when the caller asked for it — the matrix already has the dish list.
            'menu_item' => new MenuItemResource($this->whenLoaded('menuItem')),
        ];
    }
}
