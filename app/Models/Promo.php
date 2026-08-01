<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoScope;
use App\Enums\PromoType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code.
 *
 * ⚠️ NOTHING ON THIS MODEL DECIDES WHETHER A CODE MAY BE USED. That is
 * `App\Services\Promotions\PromoResolver`, and it is the only thing that decides, because a
 * promo check spread across a model, a controller and a form request is a promo check with
 * three subtly different answers.
 *
 * The helpers here answer narrow questions the resolver asks it. They are not a gate.
 *
 * @property PromoType $type
 * @property PromoScope $scope
 */
final class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'max_discount',
        'min_subtotal',
        'starts_at',
        'ends_at',
        'usage_limit',
        'usage_limit_per_customer',
        'scope',
        'first_order_only',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'scope' => PromoScope::class,
            'value' => 'int',
            'max_discount' => 'int',
            'min_subtotal' => 'int',
            'usage_limit' => 'int',
            'usage_limit_per_customer' => 'int',
            'times_used' => 'int',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'first_order_only' => 'bool',
            'is_active' => 'bool',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /**
     * ⚠️ THE ONE PLACE A CODE IS NORMALISED, AND IT IS USED ON BOTH WRITE AND READ.
     *
     * Codes are stored uppercase and matched exactly, so the unique index is the guarantee
     * that "summer" and "SUMMER" cannot be two rows. Matching with `ILIKE` instead would
     * make the index decorative and leave which row applied depending on insertion order.
     *
     * Trimming matters more than it looks: a code pasted out of WhatsApp arrives with a
     * trailing space perhaps half the time.
     */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', self::normaliseCode($code))->first();
    }

    /**
     * Live *right now* — active, and inside its window if it has one.
     *
     * A null `starts_at` means "since forever" and a null `ends_at` means "until further
     * notice". Neither is a missing value to be defaulted; both are the ordinary case.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Null `usage_limit` means unlimited. Not zero — see the migration. */
    public function isExhausted(): bool
    {
        return $this->usage_limit !== null && $this->times_used >= $this->usage_limit;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * What this promo takes off a given discountable amount, in pesewas.
     *
     * ⚠️ THE ARGUMENT IS THE **DISCOUNTABLE SUBTOTAL**, NOT THE ORDER TOTAL. The caller has
     * already narrowed it by scope and excluded the delivery fee. Passing a total in here
     * would discount a courier's fee, which she then pays out of her own pocket (§5.3).
     *
     * Capped at the amount itself: a ₵50 fixed discount on a ₵20 basket takes ₵20, never
     * ₵50. A discount larger than what is being discounted is a refund, and this is not the
     * refund path.
     */
    public function discountOn(int $discountable): int
    {
        if ($discountable <= 0) {
            return 0;
        }

        $raw = match ($this->type) {
            // Rounded, not truncated: 12.5% of 1999 is 249.875, and `(int)` would quietly
            // favour the house on every single order.
            PromoType::Percentage => (int) round($discountable * $this->value / 100),
            PromoType::Fixed => $this->value,
        };

        if ($this->type === PromoType::Percentage && $this->max_discount !== null) {
            $raw = min($raw, $this->max_discount);
        }

        return min($raw, $discountable);
    }
}
