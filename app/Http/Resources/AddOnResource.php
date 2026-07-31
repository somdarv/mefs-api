<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AddOn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `AddOn` in ../mefs/src/types/menu.ts.
 *
 * @mixin AddOn
 */
final class AddOnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
        ];
    }
}
