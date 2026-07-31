<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckoutSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The basket, before it is an order.
 *
 * ⚠️ THE SESSION IS THE REAL PATH TO AN ORDER (brief §5.8, trap §10.9). Confirming one
 * calls `OrderCreator`, and so does admin manual entry. There is no third way in.
 *
 * Lines live as JSON rather than as rows: a basket is short-lived, always read whole and
 * never queried by line. What it holds is a REFERENCE — option id and quantity — not a
 * price. The client cannot name its own price, because the client never sends one.
 */
class CheckoutSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'guest_session',
        'lines',
        'order_cycle_id',
        'cycle_day_id',
        'expires_at',
    ];

    /** The token is minted here, never supplied. */
    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'status' => CheckoutSessionStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Baskets live for a day. Long enough to leave one open overnight and come back,
     * short enough that a swept table stays small.
     */
    public const LIFETIME_HOURS = 24;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OrderCycle::class, 'order_cycle_id');
    }

    public function cycleDay(): BelongsTo
    {
        return $this->belongsTo(CycleDay::class, 'cycle_day_id');
    }

    /**
     * Random and unguessable, like the order tracking token and for the same reason: this
     * is what an unauthenticated guest holds, so a sequence anyone can walk would hand a
     * stranger someone else's basket.
     */
    public static function mintToken(): string
    {
        return Str::random(48);
    }

    public function isOpen(): bool
    {
        return $this->status === CheckoutSessionStatus::Open && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CheckoutSessionStatus::Open->value)
            ->where('expires_at', '>', now());
    }

    /**
     * Ownership, for a surface where most callers are not logged in.
     *
     * A guest proves it with the `X-Guest-Session` header; a signed-in customer with their
     * id. Neither is checked against the other — the client sends a staff token OR a guest
     * session, never both (enforced by the `Credential` union in the frontend's api client).
     */
    public function belongsToCaller(?int $customerId, ?string $guestSession): bool
    {
        if ($customerId !== null && $this->customer_id === $customerId) {
            return true;
        }

        return $guestSession !== null
            && $this->guest_session !== null
            && hash_equals($this->guest_session, $guestSession);
    }
}
