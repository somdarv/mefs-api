<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleOverride;
use App\Enums\CycleStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cooking window plus a separate, earlier ordering window. See the migration.
 */
class OrderCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'service_start_date',
        'service_end_date',
        'orders_open_at',
        'orders_close_at',
        'status',
        'order_capacity',
        'note',
        'created_by',
    ];

    /**
     * ⚠️ `override` and its audit trail are NOT fillable.
     *
     * Forcing the shop open or shut is a permissioned, logged act with a reason attached —
     * never something that rides in on a request body alongside a name change. Same
     * reasoning as `role` on User. Use `applyOverride()`.
     */
    protected function casts(): array
    {
        return [
            'service_start_date' => 'immutable_date',
            'service_end_date' => 'immutable_date',
            'orders_open_at' => 'immutable_datetime',
            'orders_close_at' => 'immutable_datetime',
            'override_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'status' => CycleStatus::class,
            'override' => CycleOverride::class,
            'order_capacity' => 'int',
        ];
    }

    /**
     * The day grid, each row carrying its own booked count.
     *
     * The count is on the RELATION rather than left to call sites, because every consumer
     * of a day needs it — `CycleGate` weighs `capacity` against it — and a call site that
     * forgets would fall through to a per-day query. One subquery here, once.
     */
    public function days(): HasMany
    {
        return $this->hasMany(CycleDay::class)
            ->withCount(['orders as orders_placed_count' => fn ($q) => $q->holdingCapacity()])
            ->orderBy('date');
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * The cycle that owns a date. At most one can, enforced by the exclusion constraint on
     * the table rather than by hope.
     */
    public function scopeCovering(Builder $query, string|CarbonImmutable $date): Builder
    {
        $key = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $query->where('service_start_date', '<=', $key)
            ->where('service_end_date', '>=', $key)
            ->where('status', '!=', CycleStatus::Archived->value);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CycleStatus::Published->value,
            CycleStatus::Closed->value,
            CycleStatus::Completed->value,
        ]);
    }

    // ── Behaviour ─────────────────────────────────────────────────────────────

    /**
     * Force the shop open or shut, or clear the override and go back to the calendar.
     *
     * Always records who and why. An override with no reason is unreviewable a week later,
     * when the only question that matters is "why were we closed on the 6th?".
     */
    public function applyOverride(?CycleOverride $override, ?string $reason, ?User $actor): void
    {
        $this->override = $override;
        $this->override_reason = $override === null ? null : $reason;
        $this->override_by = $override === null ? null : $actor?->id;
        $this->override_at = $override === null ? null : now();

        $this->save();
    }

    /**
     * Draft → published. The moment a customer can see it at all.
     *
     * A method rather than a fillable `published_at`, for the same reason as
     * `applyOverride()`: going live is a deliberate act, never something that rides in on a
     * request body alongside a name change.
     */
    public function publish(): void
    {
        $this->status = CycleStatus::Published;
        $this->published_at = now();

        $this->save();
    }

    /** Every date in the cooking window, inclusive of both ends. */
    public function serviceDates(): array
    {
        return collect(CarbonPeriod::create($this->service_start_date, $this->service_end_date))
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Orders placed against this cycle — what `CycleGate` weighs `order_capacity` against.
     *
     * ⚠️ A MISSING COUNT IS NOT A ZERO.
     *
     * The fast path reads an eager-loaded `withCount`, which is how the day list avoids one
     * query per day. But falling back to `?? 0` when nobody loaded it — which is what this
     * did while the orders table was still empty — means any caller that forgets the
     * `withCount` silently gets "nothing is booked" and the cap never binds. That is the
     * shape of the original's stock-gate bugs exactly: absent data read as a safe answer.
     *
     * So the fallback is a real count. It costs a query on the paths that forgot to eager
     * load, and it is never wrong.
     */
    public function getOrdersPlacedCountAttribute(): int
    {
        if (array_key_exists('orders_placed_count', $this->attributes)) {
            return (int) $this->attributes['orders_placed_count'];
        }

        return $this->exists ? $this->orders()->holdingCapacity()->count() : 0;
    }

    /** The eager-load every capacity-sensitive read should use. */
    public function scopeWithCapacityCounts(Builder $query): Builder
    {
        return $query->withCount([
            'orders as orders_placed_count' => fn ($q) => $q->holdingCapacity(),
        ]);
    }
}
