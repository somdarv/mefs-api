<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a receipt.
 *
 * ⚠️ EVERY DISPLAY FIELD IS A SNAPSHOT (brief §3.2). Name, price, size and category are
 * copied at order time and never joined live. A price rise next Tuesday must not rewrite
 * what last Tuesday's customer agreed to pay.
 *
 * The two foreign keys are kept anyway, and `menu_item_option_id` is load-bearing: v2
 * recipes key off it, so keeping it now — with IMS deferred — is what stops v2 needing a
 * migration sweep across every historical order (brief §12.2).
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'menu_item_option_id',
        'name',
        'unit_price',
        'size_label',
        'variant_key',
        'category',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'int',
            'quantity' => 'int',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class, 'menu_item_option_id');
    }

    /** Line total in pesewas. Display only on the client; the server owns the sum. */
    public function lineTotal(): int
    {
        return $this->unit_price * $this->quantity;
    }
}
