<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per status change, written by `OrderStatusMachine` and by nothing else.
 *
 * `actor_name` is a snapshot beside `actor_id` on purpose: staff leave, accounts are
 * deleted, and "who moved this order to cancelled" must still have an answer afterwards.
 * The foreign key nulls out; the name does not.
 *
 * No `updated_at` — an audit row that can be edited is not an audit row.
 */
class OrderStatusChange extends Model
{
    use HasFactory;

    protected $table = 'order_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_name',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
