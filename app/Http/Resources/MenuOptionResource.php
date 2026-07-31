<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MenuOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `MenuOption` in ../mefs/src/types/menu.ts. See MenuItemResource on why that is a
 * contract rather than a convention.
 *
 * @mixin MenuOption
 */
final class MenuOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_key' => $this->option_key,
            'label' => $this->label,
            'size_label' => $this->size_label,
            'variant_key' => $this->variant_key,
            // Integer minor units, always. 4000 = GHS 40.00. The frontend's formatter is the
            // only thing that divides by 100.
            'price' => $this->price,
        ];
    }
}
