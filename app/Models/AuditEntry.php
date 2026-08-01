<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded act of authority.
 *
 * ⚠️ APPEND-ONLY. There is no update path and no delete path anywhere in the application —
 * not on a controller, not on a policy, not here. An audit row that can be edited is not
 * evidence of anything, and the moment one can be, the whole table stops being worth
 * reading.
 *
 * `$timestamps` is off because the table has `created_at` and deliberately no `updated_at`:
 * a column recording when a row was last changed is a column that expects rows to change.
 */
final class AuditEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'actor_name', 'action', 'subject_type', 'subject_id',
        'summary', 'before', 'after', 'reason', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Null when the system did it — a scheduled job has no actor (see the migration). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
