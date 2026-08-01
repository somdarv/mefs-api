<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code, stored **hashed**.
 *
 * ⚠️ THIS TABLE IS A LIST OF LIVE CREDENTIALS. A read of it must not be a way in, which is
 * why nothing here ever holds the code in clear — `code_hash` goes in, and verification
 * hashes the submitted code and compares. There is deliberately no accessor that returns the
 * code, because the only thing such an accessor could be used for is logging it.
 *
 * The same reasoning as the brief's §10.14 trap on `recoverable_password`: a credential you
 * can read back is a credential that will end up in a log, a backup and a screenshot.
 */
final class Otp extends Model
{
    protected $fillable = ['phone', 'code_hash', 'expires_at', 'request_ip'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'attempts' => 'int',
        ];
    }

    /**
     * Codes are short-lived. Six digits is a million possibilities, which is plenty against
     * five attempts but not against an afternoon — the lifetime is doing as much work as the
     * length is.
     */
    public const LIFETIME_MINUTES = 10;

    /**
     * ⚠️ FIVE ATTEMPTS PER CODE, COUNTED ON THE ROW.
     *
     * Rate limiting by IP alone does not bound this: an attacker with a handful of addresses
     * gets a handful of budgets against the same code. The counter travels with the
     * credential it protects, so the code itself burns out.
     */
    public const MAX_ATTEMPTS = 5;

    /** Unconsumed, unexpired, and not yet burned through its attempts. */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
