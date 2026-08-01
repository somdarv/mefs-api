<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * The three banners the storefront shipped with as fixtures, now real rows.
 *
 * ⚠️ THE COPY IS HERS TO CHANGE AND THE PRICES IN IT ARE NOT LIVE. "₵45" here is a sentence
 * in a promotional strip, not a figure anything computes from — the menu is the price. A
 * banner claiming ₵45 while the dish sells at ₵50 is a copy problem she fixes by typing, and
 * this seeder exists so there is something to type over rather than an empty carousel.
 *
 * `updateOrCreate` on the title so re-seeding a working database does not duplicate them,
 * and does not overwrite an edit she has already made to anything else on the row.
 */
final class BannerSeeder extends Seeder
{
    public function run(): void
    {
        $banners = [
            [
                'eyebrow' => 'Thursdays',
                'title' => 'Goat Jollof',
                'body' => 'Cooked to order. Ready from midday.',
                'link_url' => '#menu-heading',
                'link_label' => 'See Thursday',
                'tone' => 'brand',
                'position' => 0,
            ],
            [
                'eyebrow' => 'Take some home',
                'title' => 'Jollof base, by the jar',
                'body' => '500ml, 1L or 2.5L. Mild or spicy. Delivered anywhere in Ghana.',
                'link_url' => '#pantry-heading',
                'link_label' => 'Browse jars',
                'tone' => 'deep',
                'position' => 1,
            ],
            [
                'eyebrow' => 'Wednesdays',
                'title' => 'Plantain Etor platter',
                'body' => 'With koobi, egg, peanut and pepper sauce.',
                'link_url' => '#menu-heading',
                'link_label' => 'See Wednesday',
                'tone' => 'soft',
                'position' => 2,
            ],
        ];

        foreach ($banners as $banner) {
            Banner::query()->updateOrCreate(
                ['title' => $banner['title']],
                $banner + ['is_active' => true],
            );
        }
    }
}
