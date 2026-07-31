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

    public function days(): HasMany
    {
        return $this->hasMany(CycleDay::class)->orderBy('date');
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

    /** Every date in the cooking window, inclusive of both ends. */
    public function serviceDates(): array
    {
        return collect(CarbonPeriod::create($this->service_start_date, $this->service_end_date))
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
    }

    /**
     * Orders placed against this cycle.
     *
     * Currently zero — the orders table arrives in M4. Declared now, and read by CycleGate,
     * so capacity is wired end to end from the start rather than retrofitted through the
     * gate later.
     */
    public function getOrdersPlacedCountAttribute(): int
    {
        return (int) ($this->attributes['orders_placed_count'] ?? 0);
    }
}
