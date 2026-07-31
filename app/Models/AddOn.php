<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Priced extras — egg, avocado, pepper sauce on the Etor platter.
 *
 * Deliberately NOT stock-deducted, matching the original (brief §7.4, which says to decide
 * this on purpose rather than inherit it by accident). IMS is deferred to v2 anyway, but the
 * decision is recorded here so v2 does not have to guess.
 */
class AddOn extends Model
{
    use HasFactory;

    protected $table = 'menu_add_ons';

    protected $fillable = ['name', 'price', 'is_active', 'position'];

    protected function casts(): array
    {
        return [
            'price' => 'int',   // minor units
            'is_active' => 'bool',
        ];
    }

    /** Pivot keys named explicitly — see the note on MenuItem::addOns(). */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(
            MenuItem::class,
            'menu_item_menu_add_on',
            'menu_add_on_id',
            'menu_item_id',
        );
    }
}
