<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One code, used once, on one order.
 *
 * ⚠️ THE EVIDENCE BEHIND `promos.times_used`. The counter is what the gate reads because
 * reading it is one integer instead of a `COUNT(*)` on every checkout; these rows are what
 * it can be rebuilt from when it drifts, and what answers "has this person used it before"
 * — a question a counter cannot answer at all.
 */
final class PromoRedemption extends Model
{
    protected $fillable = ['promo_id', 'order_id', 'customer_id', 'phone', 'discount'];

    protected function casts(): array
    {
        return ['discount' => 'int'];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
