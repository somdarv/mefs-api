<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MenuCategory;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\MenuItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\MenuOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The admin menu editor's API.
 *
 * ⚠️ PRICE IS A SEPARATE AUTHORITY FROM EVERYTHING ELSE (brief §3.3).
 *
 * `menu.manage` covers dishes, options, photos and availability. `menu.price` covers what
 * something costs, and nothing here writes a price without it. Collapsing the two means
 * anyone who can mark a dish sold out can also reprice it — which is precisely the coarse
 * grant the brief spends §4.3 on.
 */
final class MenuItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuView);

        $items = MenuItem::query()
            ->with(['options', 'addOns'])
            ->when($request->string('category')->isNotEmpty(), fn ($q) => $q->where('category', $request->string('category')))
            // Retired dishes are visible to staff. She needs to see what she has stopped
            // cooking in order to bring it back.
            ->when($request->boolean('include_inactive') === false, fn ($q) => $q->active())
            ->orderBy('category')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(MenuItemResource::collection($items), 'Menu items');
    }

    public function show(Request $request, MenuItem $item): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuView);

        return ApiResponse::success(
            new MenuItemResource($item->load(['options', 'addOns'])),
            $item->name,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuManage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'unique:menu_items,slug'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(MenuCategory::class)],
            'default_service_weekdays' => ['array'],
            'default_service_weekdays.*' => ['integer', 'between:1,7'],
            'position' => ['integer', 'min:0'],

            // A dish MUST arrive with at least one option. A dish with none can never be
            // sold and (in v2) can never be costed — see the menu migration.
            'options' => ['required', 'array', 'min:1'],
            'options.*.option_key' => ['required', 'string', 'max:60'],
            'options.*.label' => ['required', 'string', 'max:120'],
            'options.*.size_label' => ['nullable', 'string', 'max:40'],
            'options.*.variant_key' => ['nullable', 'string', 'max:40'],
            'options.*.price' => ['required', 'integer', 'min:0'],
        ]);

        // Setting a price at creation is still setting a price.
        $this->authorizePermission($request, Permission::MenuPrice);

        $item = DB::transaction(function () use ($data): MenuItem {
            $item = MenuItem::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'default_service_weekdays' => $data['default_service_weekdays'] ?? [],
                'position' => $data['position'] ?? 0,
            ]);

            foreach ($data['options'] as $index => $option) {
                MenuOption::reinstate($item, $option['option_key'], [
                    'menu_item_id' => $item->id,
                    'option_key' => $option['option_key'],
                    'label' => $option['label'],
                    'size_label' => $option['size_label'] ?? null,
                    'variant_key' => $option['variant_key'] ?? null,
                    'price' => $option['price'],
                    'position' => $index,
                ]);
            }

            // Serve it from every active branch by default. One kitchen today; this is what
            // stops a second one opening to an empty menu.
            $item->branches()->sync(
                Branch::query()->where('is_active', true)->pluck('id')
                    ->mapWithKeys(fn ($id) => [$id => ['is_available' => true]])
                    ->all(),
            );

            return $item;
        });

        return ApiResponse::created(
            new MenuItemResource($item->load(['options', 'addOns'])),
            "{$item->name} added",
        );
    }

    /** Everything except price. See the class docblock. */
    public function update(Request $request, MenuItem $item): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuManage);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:140', Rule::unique('menu_items', 'slug')->ignore($item->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::enum(MenuCategory::class)],
            'default_service_weekdays' => ['sometimes', 'array'],
            'default_service_weekdays.*' => ['integer', 'between:1,7'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $item->update($data);

        return ApiResponse::success(
            new MenuItemResource($item->fresh()->load(['options', 'addOns'])),
            "{$item->name} updated",
        );
    }

    /**
     * Options, including price. Gated on BOTH permissions because this endpoint writes both
     * kinds of fact, and a request that only means to rename a size still carries a price.
     */
    public function syncOptions(Request $request, MenuItem $item): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuManage);
        $this->authorizePermission($request, Permission::MenuPrice);

        $data = $request->validate([
            'options' => ['required', 'array', 'min:1'],
            'options.*.option_key' => ['required', 'string', 'max:60'],
            'options.*.label' => ['required', 'string', 'max:120'],
            'options.*.size_label' => ['nullable', 'string', 'max:40'],
            'options.*.variant_key' => ['nullable', 'string', 'max:40'],
            'options.*.price' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($item, $data): void {
            $keptKeys = [];

            foreach ($data['options'] as $index => $option) {
                MenuOption::reinstate($item, $option['option_key'], [
                    'menu_item_id' => $item->id,
                    'option_key' => $option['option_key'],
                    'label' => $option['label'],
                    'size_label' => $option['size_label'] ?? null,
                    'variant_key' => $option['variant_key'] ?? null,
                    'price' => $option['price'],
                    'position' => $index,
                ]);

                $keptKeys[] = $option['option_key'];
            }

            // SOFT delete what she removed, never a hard one: historical order lines point
            // at these ids, and a receipt that cannot resolve its own item is worse than a
            // retired option sitting in the table.
            $item->options()->whereNotIn('option_key', $keptKeys)->delete();
        });

        return ApiResponse::success(
            new MenuItemResource($item->fresh()->load(['options', 'addOns'])),
            'Options updated',
        );
    }

    public function uploadImage(Request $request, MenuItem $item): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuManage);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        // Replace rather than accumulate — re-uploading a photo six times should not leave
        // five orphans on disk.
        if ($item->image_path !== null) {
            Storage::disk('public')->delete($item->image_path);
        }

        $path = $request->file('image')->store("menu/{$item->slug}", 'public');

        $item->update(['image_path' => $path]);

        return ApiResponse::success(
            new MenuItemResource($item->fresh()->load(['options', 'addOns'])),
            'Photo updated',
        );
    }

    /**
     * Retiring a dish, not erasing it.
     *
     * Soft delete for the same reason as options: order history points here. "Delete" in
     * the UI means "stop offering", and that is what she actually wants — a dish she stops
     * cooking in July may come back in December.
     */
    public function destroy(Request $request, MenuItem $item): JsonResponse
    {
        $this->authorizePermission($request, Permission::MenuManage);

        $item->update(['is_active' => false]);
        $item->delete();

        return ApiResponse::success(null, "{$item->name} retired");
    }

    private function authorizePermission(Request $request, Permission $permission): void
    {
        abort_unless(
            $request->user()->can($permission->value),
            403,
            "This action requires the {$permission->value} permission.",
        );
    }
}
