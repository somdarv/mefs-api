<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\BannerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

/**
 * The storefront's banner strip. Public, unauthenticated.
 *
 * ⚠️ `live()` IS THE WHOLE ENDPOINT. A customer receives banners that are switched on and
 * inside their window, and nothing else — a scheduled banner must not be readable before it
 * starts, or "launching Friday" is public on Tuesday to anyone who opens the network tab.
 */
final class BannerController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            ['banners' => BannerResource::collection(Banner::query()->live()->get())],
            'Banners',
        );
    }
}
