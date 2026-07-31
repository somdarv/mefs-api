<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
final class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'menu_item_id' => $this->menu_item_id,

            // Load-bearing: v2 recipes key off the option id, so it travels even though IMS
            // is deferred (brief §12.2).
            'menu_item_option_id' => $this->menu_item_option_id,

            // Snapshots. Never re-read from the catalogue — that is the whole point.
            'name' => $this->name,
            'unit_price' => $this->unit_price,
            'size_label' => $this->size_label,
            'variant_key' => $this->variant_key,
            'category' => $this->category,

            'quantity' => $this->quantity,
            'line_total' => $this->lineTotal(),
            'notes' => $this->notes,
        ];
    }
}
