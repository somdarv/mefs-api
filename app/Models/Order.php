<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The centre of the system (brief §3.2).
 *
 * ⚠️ NOTHING CREATES ONE OF THESE DIRECTLY. `OrderCreator` is the only path in, and
 * `OrderStatusMachine` the only thing that moves one along. Both are single because the
 * original had two creation routes, gated one of them, and sold 23 portions against a
 * balance of 6 four minutes after the gate deployed (brief §5.8, trap §10.9).
 *
 * Every money column is an integer count of pesewas. 4000 is GHS 40.00.
 */
class Order extends Model
{
    use HasFactory;

    /**
     * ⚠️ WHAT IS ABSENT MATTERS MORE THAN WHAT IS PRESENT.
     *
     * `status`, `order_number`, `tracking_token`, `is_paid`, `payment_status` and every
     * money column are NOT fillable. They are outcomes of a service call, not fields on a
     * form, and a mass-assign that could set `total` or `status` turns a request body into
     * a discount code and a status machine bypass. Same reasoning as `override` on
     * OrderCycle and `role` on User.
     */
    protected $fillable = [
        'contact_name',
        'contact_phone',
        'delivery_address',
        'delivery_area',
        'gps_code',
        'delivery_note',
        'internal_notes',
        'courier_name',
        'courier_reference',
    ];

    /** Staff-only. Hidden so it cannot reach a customer surface by accident. */
    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'order_type' => OrderType::class,
            'source' => OrderSource::class,
            'payment_status' => PaymentStatus::class,

            'branch_snapshot' => 'array',
            'fulfil_date' => 'immutable_date',

            'subtotal' => 'int',
            'service_charge' => 'int',
            'delivery_fee' => 'int',
            'discount' => 'int',
            'total' => 'int',

            'is_manual_entry' => 'bool',
            'is_paid' => 'bool',
            'is_simulated' => 'bool',
            'hold_expired' => 'bool',

            'slot_hold_expires_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'placed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Null for a guest, and every join must tolerate that (brief trap §10.6). */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OrderCycle::class, 'order_cycle_id');
    }

    /**
     * Set only when a code actually took something off, so a join over it counts
     * redemptions rather than attempts.
     *
     * ⚠️ NOT THE SOURCE OF TRUTH FOR THE RECEIPT. `promo_code` is the snapshot — the code as
     * typed, kept whether it applied or not, and it survives the promo being deleted. This
     * relation nulls out when that happens, exactly as it should.
     */
    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function cycleDay(): BelongsTo
    {
        return $this->belongsTo(CycleDay::class, 'cycle_day_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * ⚠️ THE ONE THE CAPACITY NUMBERS ARE BUILT ON.
     *
     * A cancelled order gives its slot back; everything else is still holding one, paid or
     * not. Widen this and every "fully booked" verdict in `CycleGate` changes with it, so
     * it is a scope rather than a repeated `where` that someone will one day write
     * differently in a fifth place.
     */
    public function scopeHoldingCapacity(Builder $query): Builder
    {
        return $query->where('status', '!=', OrderStatus::Cancelled->value);
    }

    /** URL lookups go by token, never by id or order number (brief §5.6). */
    public function scopeForTracking(Builder $query, string $token): Builder
    {
        return $query->where('tracking_token', $token);
    }

    /**
     * Unpaid orders whose hold has run out (departure #6).
     *
     * The scheduled job reads exactly this, so "which orders have expired" has one
     * definition rather than one per caller.
     *
     * ⚠️ `received` ONLY, AND THAT IS THE WHOLE JUDGEMENT.
     *
     * Accepting an order is a commitment: she has looked at it and decided to cook it. If
     * the hold then expires, cancelling would throw away food she is already planning —
     * exactly the wrong direction for an automated job to be wrong in. An accepted order
     * that never pays is a conversation, not a cron job.
     *
     * So the clock only ever releases a slot nobody has committed to. Everything past
     * `received` is hers to chase.
     */
    public function scopeHoldExpired(Builder $query): Builder
    {
        return $query->where('is_paid', false)
            ->where('hold_expired', false)
            ->whereNotNull('slot_hold_expires_at')
            ->where('slot_hold_expires_at', '<=', now())
            ->where('status', OrderStatus::Received->value);
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    /**
     * Revenue, excluding what is only passing through (brief §5.3).
     *
     * She uses a third-party courier: the delivery fee is collected and handed over. It is
     * not income, and counting it overstates every revenue figure in the business. This
     * lives on the model so that no analytics query has to remember to subtract it.
     */
    public function revenueTotal(): int
    {
        return $this->delivery_fee_collection === 'third_party'
            ? $this->total - $this->delivery_fee
            : $this->total;
    }

    /** What the customer is told the branch is — the snapshot, never a live join. */
    public function branchSnapshot(): array
    {
        return $this->branch_snapshot ?? [];
    }

    public function isCancellable(): bool
    {
        return ! in_array($this->status, [OrderStatus::Completed, OrderStatus::Cancelled], true);
    }
}
