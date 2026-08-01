<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ⚠️ THE SAME SHAPE ON BOTH SURFACES, AND THAT IS SAFE HERE.
 *
 * Unlike `PromoResource`, there is nothing on a banner a customer should not see — it is
 * copy written to be published. The scheduling fields are the only thing close, and a
 * customer only ever receives banners that are already live, so `starts_at` on one of those
 * is a date in the past.
 *
 * @mixin Banner
 */
final class BannerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Banner $banner */
        $banner = $this->resource;

        return [
            'id' => $banner->id,
            'eyebrow' => $banner->eyebrow,
            'title' => $banner->title,
            'body' => $banner->body,

            /*
             * ⚠️ SHAPED AS THE CAROUSEL WANTS IT, NOT AS THE TABLE STORES IT.
             *
             * `promo-carousel.tsx` takes a `cta: { label, href } | null` — one thing that is
             * either there or not — while the columns are two independently nullable
             * strings. Collapsing them here rather than in the component means the "a URL
             * with no label" case is resolved once, on the side that can see the validation
             * rule that makes it impossible.
             */
            'cta' => $banner->link_url === null || $banner->link_label === null
                ? null
                : ['label' => $banner->link_label, 'href' => $banner->link_url],

            // Kept flat as well, because the editor binds to the two fields separately.
            'link_url' => $banner->link_url,
            'link_label' => $banner->link_label,

            'image_url' => $banner->image_path,
            'tone' => $banner->tone,

            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),

            'position' => $banner->position,
            'is_active' => $banner->is_active,

            // Her switch versus whether it would actually show right now — the same
            // distinction `PromoResource` draws, and for the same reason: a banner that is
            // active but starts on Friday looks on in a list that only shows the switch.
            'is_live' => $banner->isLive(),
        ];
    }
}
