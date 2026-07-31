<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MenuCategory;
use App\Models\AddOn;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\MenuOption;
use Illuminate\Database\Seeder;

/**
 * The catalog, transcribed from ../mefs/src/lib/menu/catalog.ts — which was itself
 * transcribed from her flyers in ../mefs/docs/ref images/.
 *
 * Prices are minor units: 4000 = GHS 40.00.
 *
 * ⚠️ TWO THINGS HERE ARE NOT REAL AND MUST BE CONFIRMED BEFORE LAUNCH:
 *
 *  1. **Jollof-base prices are invented.** They are not on any flyer.
 *  2. **Shito and kelewele spice are invented products.** She has not said she sells
 *     either. They exist so the pantry shelf has something to be judged on, and between
 *     them they cover the shapes an option can take — size-only, no-options, and (with the
 *     jollof base) size x variant.
 *
 * Transcription notes carried over from the frontend fixture:
 *  - The flyer spells Monday "Wakkye" and Wednesday "Waakye". One dish, spelled "Waakye".
 *  - Wednesday lists "Etor - GHS 40" while the product sheet sells a GHS 100 STANDARD
 *    platter. Modelled as one dish with two options, which is what the option model is for.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $addOns = $this->seedAddOns();
        $branchIds = Branch::query()->where('is_active', true)->pluck('id');

        foreach ($this->catalog() as $position => $entry) {
            $item = MenuItem::query()->updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'] ?? null,
                    'category' => $entry['category'],
                    'default_service_weekdays' => $entry['service_days'],
                    'position' => $position,
                    'is_active' => true,
                ],
            );

            foreach ($entry['options'] as $index => $option) {
                // reinstate(), not create(): re-running the seeder after retiring an option
                // must restore it rather than collide on the unique index (trap §10.5).
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

            if (($entry['add_ons'] ?? []) !== []) {
                $item->addOns()->sync(
                    collect($entry['add_ons'])->map(fn (string $name) => $addOns[$name])->all(),
                );
            }

            $item->branches()->syncWithoutDetaching(
                $branchIds->mapWithKeys(fn ($id) => [$id => ['is_available' => true]])->all(),
            );
        }
    }

    /** @return array<string, int> name => id */
    private function seedAddOns(): array
    {
        $addOns = [
            ['name' => 'Egg', 'price' => 500],
            ['name' => 'Pepper sauce', 'price' => 500],
            ['name' => 'Peanut', 'price' => 500],
            ['name' => 'Peanut butter', 'price' => 500],
            ['name' => 'Avocado', 'price' => 2000],
        ];

        $map = [];

        foreach ($addOns as $position => $addOn) {
            $model = AddOn::query()->updateOrCreate(
                ['name' => $addOn['name']],
                ['price' => $addOn['price'], 'position' => $position, 'is_active' => true],
            );

            $map[$addOn['name']] = $model->id;
        }

        return $map;
    }

    /** A dish the customer perceives as having no choice still gets one option row. */
    private function single(int $price): array
    {
        return [[
            'option_key' => 'standard',
            'label' => 'Standard',
            'size_label' => null,
            'variant_key' => null,
            'price' => $price,
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        return [
            // ── Meals: the weekly rotation ────────────────────────────────────
            [
                'slug' => 'waakye',
                'name' => 'Waakye',
                'description' => 'Rice and beans, cooked the long way.',
                'category' => MenuCategory::Meal->value,
                'service_days' => [1, 3],
                'options' => $this->single(4000),
            ],
            [
                'slug' => 'chinkafa',
                'name' => 'Chinkafa',
                'category' => MenuCategory::Meal->value,
                'service_days' => [1],
                'options' => $this->single(4000),
            ],
            [
                'slug' => 'toolo-beef-braised-rice',
                'name' => 'Toolo Beef Braised Rice',
                'category' => MenuCategory::Meal->value,
                'service_days' => [2],
                'options' => $this->single(4000),
            ],
            [
                'slug' => 'chicken-franks-fried-rice',
                'name' => 'Chicken Franks Fried Rice',
                'category' => MenuCategory::Meal->value,
                'service_days' => [2],
                'options' => $this->single(4500),
            ],
            [
                'slug' => 'plantain-etor',
                'name' => 'Plantain Etor',
                'description' => 'Mashed plantain. The platter comes loaded.',
                'category' => MenuCategory::Meal->value,
                'service_days' => [3],
                'options' => [
                    ['option_key' => 'plain', 'label' => 'Plain', 'variant_key' => 'plain', 'price' => 4000],
                    ['option_key' => 'standard', 'label' => 'Standard platter', 'variant_key' => 'standard', 'price' => 10000],
                ],
                'add_ons' => ['Egg', 'Pepper sauce', 'Peanut', 'Peanut butter', 'Avocado'],
            ],
            [
                'slug' => 'goat-jollof',
                'name' => 'Goat Jollof',
                'category' => MenuCategory::Meal->value,
                'service_days' => [4],
                'options' => $this->single(4500),
            ],
            [
                'slug' => 'jollof-with-chicken',
                'name' => 'Jollof with Chicken',
                'category' => MenuCategory::Meal->value,
                'service_days' => [4, 5],
                'options' => $this->single(4000),
            ],
            [
                'slug' => 'beef-jollof',
                'name' => 'Beef Jollof',
                'category' => MenuCategory::Meal->value,
                'service_days' => [4],
                'options' => $this->single(4000),
            ],
            [
                'slug' => 'gari-fotor',
                'name' => 'Gari Fotor',
                'category' => MenuCategory::Meal->value,
                'service_days' => [5],
                'options' => $this->single(3500),
            ],

            // ── Pantry: shelf-stable, no rotation slot, ships nationwide ──────
            [
                'slug' => 'jollof-base',
                'name' => 'Jollof base',
                'description' => 'Tasty jollof made easy peasy. Shelf-stable, ready when you are.',
                'category' => MenuCategory::Pantry->value,
                'service_days' => [],
                'options' => [
                    // ⚠️ PLACEHOLDER PRICES — not on the flyer.
                    ['option_key' => '500ml-mild', 'label' => '500ml mild', 'size_label' => '500ml', 'variant_key' => 'mild', 'price' => 6000],
                    ['option_key' => '500ml-spicy', 'label' => '500ml spicy', 'size_label' => '500ml', 'variant_key' => 'spicy', 'price' => 6000],
                    ['option_key' => '1l-mild', 'label' => '1L mild', 'size_label' => '1L', 'variant_key' => 'mild', 'price' => 11000],
                    ['option_key' => '1l-spicy', 'label' => '1L spicy', 'size_label' => '1L', 'variant_key' => 'spicy', 'price' => 11000],
                    ['option_key' => '2-5l-mild', 'label' => '2.5L mild', 'size_label' => '2.5L', 'variant_key' => 'mild', 'price' => 25000],
                    ['option_key' => '2-5l-spicy', 'label' => '2.5L spicy', 'size_label' => '2.5L', 'variant_key' => 'spicy', 'price' => 25000],
                ],
            ],
            [
                // ⚠️ INVENTED PRODUCT.
                'slug' => 'shito',
                'name' => 'Shito',
                'description' => 'Black pepper sauce. Keeps for months in the fridge.',
                'category' => MenuCategory::Pantry->value,
                'service_days' => [],
                'options' => [
                    ['option_key' => '250ml', 'label' => '250ml', 'size_label' => '250ml', 'price' => 4500],
                    ['option_key' => '500ml', 'label' => '500ml', 'size_label' => '500ml', 'price' => 8000],
                ],
            ],
            [
                // ⚠️ INVENTED PRODUCT.
                'slug' => 'kelewele-spice',
                'name' => 'Kelewele spice',
                'description' => 'The rub. Bring your own plantain.',
                'category' => MenuCategory::Pantry->value,
                'service_days' => [],
                'options' => $this->single(3000),
            ],
        ];
    }
}
