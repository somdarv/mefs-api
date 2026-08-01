<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who wanted food that had run out.
 *
 * @property string $status waiting | notified | converted | expired
 */
final class WaitlistEntry extends Model
{
    protected $fillable = [
        'cycle_day_id', 'menu_item_id', 'name', 'phone', 'quantity', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'int',
            'notified_at' => 'immutable_datetime',
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

    /**
     * ⚠️ FIRST IN, FIRST TEXTED. `orderBy('id')` and nothing else.
     *
     * The alternative — texting whoever wants the most portions, so the freed capacity is
     * used up in one message — is more efficient and is the wrong call. Somebody who joined
     * on Monday being skipped for somebody who joined on Thursday is the kind of unfairness
     * a small kitchen's customers notice and mention to each other.
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting')->orderBy('id');
    }

    public function markNotified(): void
    {
        $this->forceFill(['status' => 'notified', 'notified_at' => now()])->save();
    }
}
