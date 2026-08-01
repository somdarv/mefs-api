<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What have I ordered?"
 *
 * ⚠️⚠️ MATCHED ON THE PHONE NUMBER, NOT ON `customer_id`. ⚠️⚠️
 *
 * This is the decision that makes accounts worth having at all. A guest can order without
 * one — that is deliberate, and most orders are placed that way — so those orders carry
 * `customer_id: null`. Keying history on the foreign key would show a customer who has
 * ordered eleven times an empty list on the day they finally sign up, and the obvious
 * conclusion is that the account lost their history.
 *
 * The phone is the identity this business actually uses. It is what she calls, and it is
 * verified by the OTP the customer just went through — which is precisely what makes it safe
 * to key on: they proved they hold the number before they could get here.
 *
 * ⚠️ AND THE NUMBER COMES FROM THE PRINCIPAL, NEVER THE REQUEST (brief Law 2). A `phone`
 * query parameter here would be an order-history endpoint for anybody else's number.
 */
final class CustomerOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user()?->customer;

        abort_if($customer === null, 403, 'This is a customer account route.');

        $orders = Order::query()
            ->with(['items'])
            ->where('contact_phone', $customer->phone)
            ->orderByDesc('id')
            // Capped rather than paginated. This is a list somebody scrolls once; a hundred
            // orders is more history than any customer here has, and a pager on a screen
            // nobody reaches page two of is machinery for nothing.
            ->limit(100)
            ->get();

        /*
         * ⚠️ `OrderResource`, THE CUSTOMER ONE — never `StaffOrderResource`. The staff
         * version carries `internal_notes`, the courier reference and the audit trail. Two
         * resources exist so that this distinction is a type rather than a habit.
         */
        return ApiResponse::success(
            ['orders' => OrderResource::collection($orders)],
            'Your orders',
        );
    }
}
