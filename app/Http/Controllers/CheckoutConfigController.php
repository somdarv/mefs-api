<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * The one public endpoint exposing runtime settings (brief §5.2).
 *
 * The storefront needs to know whether delivery is on and what the fee looks like, so it can
 * render a plausible total. **That total is display only** — money is computed server-side
 * at checkout, and a client that disagrees is wrong, not authoritative.
 *
 * Only `is_public` settings appear here, and that column defaults to false, so a new setting
 * is private until someone deliberately opens it. Adding one can never accidentally leak
 * `manual_order_hold_minutes` or the delivery-fee collection model to the public.
 */
final class CheckoutConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'currency' => [
                'code' => 'GHS',
                'symbol' => '₵',
                // Money crosses the wire as an integer count of these. 100 pesewas = ₵1.
                'minor_units_per_major' => 100,
                'fraction_digits' => 2,
            ],
            'settings' => SystemSetting::publicValues(),
        ], 'Checkout configuration');
    }
}
