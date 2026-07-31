<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One date inside a cycle's cooking window.
 *
 * Exists as a row rather than being derived from the window, because each day carries state
 * of its own — closed, capped, re-cutoff, annotated — and none of that can hang off a date
 * computed on the fly.
 */
class CycleDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_cycle_id',
        'date',
        'is_open',
        'cutoff_at',
        'capacity',
        'kitchen_note',
    ];

    /** Staff-only. Kept out of serialisation so it cannot reach a customer surface. */
    protected $hidden = ['kitchen_note'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            'is_open' => 'bool',
            'capacity' => 'int',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OrderCycle::class, 'order_cycle_id');
    }

    /** The dish matrix for this day. */
    public function items(): HasMany
    {
        return $this->hasMany(CycleDayItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Orders for this date. Zero until M4 — see the note on OrderCycle.
     */
    public function getOrdersPlacedCountAttribute(): int
    {
        return (int) ($this->attributes['orders_placed_count'] ?? 0);
    }
}
