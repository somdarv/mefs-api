<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer profile, keyed by phone.
 *
 * `user_id` is nullable: a guest orders with no account, and still needs somewhere to hang
 * an order history. Every query that joins customer must tolerate that null (brief §10.6).
 */
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'default_address',
        'default_area',
        'default_delivery_note',
    ];

    /** Staff-only. Kept out of every serialisation so it cannot reach a customer surface. */
    protected $hidden = ['internal_notes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAccount(): bool
    {
        return $this->user_id !== null;
    }
}
