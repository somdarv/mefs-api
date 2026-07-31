<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the dish matrix: this dish, on this day.
 *
 * The per-date TRUTH about what she cooks. `menu_items.default_service_weekdays` only
 * pre-fills these when a cycle is created; after that they diverge freely, which is the
 * point — putting Waakye on a Thursday should not mean editing the weekly template.
 */
class CycleDayItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_day_id',
        'menu_item_id',
        'is_available',
        'portion_capacity',
        'portions_sold',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'bool',
            'portion_capacity' => 'int',
            'portions_sold' => 'int',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(CycleDay::class, 'cycle_day_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** Portions left, or null when this dish is uncapped that day. */
    public function remaining(): ?int
    {
        return $this->portion_capacity === null
            ? null
            : max(0, $this->portion_capacity - $this->portions_sold);
    }

    public function isSoldOut(): bool
    {
        return $this->remaining() === 0;
    }
}
