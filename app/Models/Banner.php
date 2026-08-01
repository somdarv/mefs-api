<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A promotional strip on the storefront.
 *
 * ⚠️ CONTENT, NOT A DISCOUNT. A banner can *mention* a code; it cannot apply one. Wiring a
 * `promo_id` onto this table would make the storefront's copy the thing that decides what a
 * customer pays, and the only thing that decides that is `PromoResolver`.
 */
final class Banner extends Model
{
    protected $fillable = [
        'title', 'body', 'link_url', 'link_label', 'tone',
        'starts_at', 'ends_at', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'position' => 'int',
            'is_active' => 'bool',
        ];
    }

    /**
     * What a customer should actually see right now.
     *
     * ⚠️ THE SAME WINDOW LOGIC AS `Promo::scopeLive`, AND IT IS DUPLICATED ON PURPOSE. The
     * two are four lines each and answer questions about different tables; a shared scope
     * would be a base class inherited for the sake of eight lines, and the first time one of
     * them needs a third condition it would grow a flag.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            // Ties break on id so the order is total — without it, two banners at position 0
            // can swap places between requests and the strip appears to shuffle itself.
            ->orderBy('position')
            ->orderBy('id');
    }

    public function isLive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
