<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MenuCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One dish, one row (brief §3.3). See the menu migration for why that matters more than
 * tidiness.
 */
class MenuItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'image_path',
        'category',
        'default_service_weekdays',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'category' => MenuCategory::class,
            'default_service_weekdays' => 'array',
            'is_active' => 'bool',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuOption::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Pivot keys named explicitly. Laravel derives the foreign key from the MODEL class —
     * `AddOn` yields `add_on_id` — but the table is `menu_add_ons` and its key is
     * `menu_add_on_id`. Leaving it to the convention produces an "undefined column" only
     * when something actually attaches an add-on, not when the relation is declared.
     */
    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(
            AddOn::class,
            'menu_item_menu_add_on',
            'menu_item_id',
            'menu_add_on_id',
        )
            ->orderBy('position')
            ->orderBy('menu_add_ons.id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'menu_item_branches')
            ->withPivot('is_available')
            ->withTimestamps();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, MenuCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    /**
     * Dishes whose ROTATION TEMPLATE covers an ISO weekday.
     *
     * ⚠️ This is not what the customer sees once cycles exist (M3). The per-date truth is
     * `cycle_day_items`; this scope only pre-fills a new cycle's matrix and serves the
     * storefront until cycles land.
     */
    public function scopeCookedOnWeekday(Builder $query, int $weekday): Builder
    {
        // jsonb containment: does the array contain this number?
        return $query->whereRaw('default_service_weekdays @> ?::jsonb', [json_encode([$weekday])]);
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    /**
     * URL for the card, or null so it renders the branded fallback.
     *
     * A path already rooted at `/` is served by the STOREFRONT's own `public/` directory —
     * that is how the placeholder photography in ../mefs/public/menu stays wired up during
     * development. Anything else is an upload on the API's public disk.
     *
     * ⚠️ Those placeholders are freely-licensed stock, not her food, and must not reach a
     * customer. See ../mefs/public/menu/ATTRIBUTION.md. Every one is replaced by a real
     * upload before launch, at which point the `/`-rooted branch stops being used.
     */
    public function imageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        if (str_starts_with($this->image_path, '/')) {
            return $this->image_path;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    /**
     * Cheapest option, for the "from ₵x" affordance on a multi-option card.
     * Mirrors `cheapestOption()` in the frontend's types/menu.ts.
     */
    public function cheapestOption(): ?MenuOption
    {
        return $this->options->sortBy('price')->first();
    }

    /** Shelf-stable goods belong to no rotation slot, so they sell whenever. */
    public function isDayIndependent(): bool
    {
        return $this->default_service_weekdays === [];
    }
}
