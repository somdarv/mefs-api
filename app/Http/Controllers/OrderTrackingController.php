<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * "Where is my order?" — unauthenticated, by token.
 *
 * ⚠️ IT HAS TO BE UNAUTHENTICATED. This is where the payment gateway redirects someone who
 * has no account, so a login wall here means a paying customer lands on a sign-in form.
 *
 * ⚠️ WHICH IS EXACTLY WHY IT IS KEYED ON `tracking_token` AND NOT ON `order_number` OR `id`.
 *
 * A sequential number in the URL is walkable: `/orders/A001`, `/orders/A002`, and a stranger
 * can read off the day's volume, every customer's name and every phone number. The original
 * throttled that route and called it mitigation. A throttle slows the enumeration; it does
 * not make the identifier private. A 48-character random token does (brief §5.6).
 *
 * `OrderResource` — not the staff one — so `internal_notes`, who moved the status and the
 * slot-hold clock stay on the other side of the boundary.
 */
final class OrderTrackingController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $order = Order::query()
            ->forTracking($token)
            ->with(['items', 'statusHistory'])
            ->first();

        // 404 rather than a distinguishable "wrong token" — the honest answer to a
        // stranger's guess is that there is nothing here.
        abort_if($order === null, 404, 'Not found.');

        return ApiResponse::success(
            new OrderResource($order),
            "Order {$order->order_number}",
        );
    }
}
