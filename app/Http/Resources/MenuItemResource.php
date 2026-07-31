<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ⚠️ THIS SHAPE IS A CONTRACT.
 *
 * It must match `MenuItem` in ../mefs/src/types/menu.ts field for field. That type is
 * already consumed by dish-card, pantry-card, dish-thumb and the cart store, so a rename
 * here is a frontend change everywhere — and, worse, a *missing* field arrives as
 * `undefined` rather than an error, which renders as an empty card instead of a crash.
 *
 * Two deliberate name differences from the database:
 *   - `image_path` → `image_url` (absolute, or null for the branded fallback)
 *   - `default_service_weekdays` → `service_days`
 *
 * The second is load-bearing and temporary: the column is a rotation TEMPLATE, but until
 * cycles land (M3) it is also what drives the storefront. Once `/menu/day/{date}` reads
 * `cycle_day_items`, this field becomes advisory.
 *
 * @mixin MenuItem
 */
final class MenuItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'category' => $this->category->value,
            'service_days' => $this->default_service_weekdays,
            'options' => MenuOptionResource::collection($this->whenLoaded('options')),
            'add_ons' => AddOnResource::collection($this->whenLoaded('addOns')),
        ];
    }
}
