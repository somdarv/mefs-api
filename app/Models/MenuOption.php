<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The sellable unit. Order lines reference THIS, not the dish (brief §6, §12.2).
 */
class MenuOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menu_item_options';

    protected $fillable = [
        'menu_item_id',
        'option_key',
        'label',
        'size_label',
        'variant_key',
        'price',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Integer minor units. Cast so a string from a form never silently becomes
            // "4000" and then compares wrong against an integer price elsewhere.
            'price' => 'int',
            'is_active' => 'bool',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * ⚠️ THE ONLY SAFE WAY TO ADD AN OPTION (brief trap §10.5).
     *
     * `UNIQUE(menu_item_id, option_key)` is enforced by Postgres and **a soft-deleted row
     * still occupies its index entry**. So deleting `500ml-mild` and adding it back — which
     * is an ordinary thing to do after a mistake — fails with a constraint violation on a
     * row the UI says does not exist.
     *
     * `withTrashed()->firstOrNew()` resolves it: an existing soft-deleted row is restored
     * and updated in place rather than duplicated. That is also the *correct* behaviour
     * beyond dodging the error, because the id is load-bearing — restoring it keeps every
     * historical order line pointing at the right thing.
     */
    public static function reinstate(MenuItem $item, string $optionKey, array $attributes): self
    {
        /** @var self $option */
        $option = self::withTrashed()->firstOrNew([
            'menu_item_id' => $item->id,
            'option_key' => $optionKey,
        ]);

        $option->fill($attributes);

        if ($option->trashed()) {
            $option->deleted_at = null;
        }

        $option->save();

        return $option;
    }

    /** "500ml mild", "Standard" — what appears on a receipt. */
    public function displayLabel(): string
    {
        return $this->label;
    }
}
