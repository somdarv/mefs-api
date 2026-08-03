<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment ATTEMPT. Not one per order — a customer who abandons Paystack and comes back
 * leaves two rows here, and only one of them completed.
 *
 * ⚠️ `reference` IS UNIQUE, AND THAT IS THE WHOLE IDEMPOTENCY STORY. Paystack retries
 * webhooks; assume duplicates. A unique index makes a replay a no-op at the database level
 * rather than a second credit, which is not something to leave to an `if` in a controller
 * that two concurrent workers can both pass (brief §5.7).
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'is_simulated',
        'reference',
        'amount',
        'currency',
        'status',
        'channel',
        'fee',
        'settled_amount',
        'settled_at',
        'payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payload' => 'array',
            'is_simulated' => 'bool',
            'amount' => 'int',
            'fee' => 'int',
            'settled_amount' => 'int',
            'paid_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Completed->value);
    }

    /**
     * What the business actually kept.
     *
     * Gross is not revenue. `settled_amount` is what the gateway paid out; until settlement
     * lands (M6) it is null, and a null here is unknown rather than zero — the difference
     * the whole money dashboard turns on.
     */
    public function netToBusiness(): ?int
    {
        return $this->settled_amount;
    }
}
