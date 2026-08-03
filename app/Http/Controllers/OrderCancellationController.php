<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Ordering\IllegalTransition;
use App\Services\Ordering\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer asking to cancel their own order.
 *
 * ⚠️ IT ASKS. IT DOES NOT CANCEL.
 *
 * This is a pre-order kitchen: an order placed on the 1st for the 12th may already have been
 * shopped for, and in some cases cooked. Letting a customer cancel outright would hand them
 * a button that destroys food she has already paid for. So the order moves to
 * `cancel_requested` and waits for her — which is precisely the state
 * `OrderStatusMachine::rejectCancellation()` was built to answer and, until now, nothing
 * could produce.
 *
 * ── UNAUTHENTICATED, BY TOKEN, LIKE THE TRACKING PAGE ─────────────────────────
 *
 * Same reasoning as `OrderTrackingController`: most customers have no account, and the
 * tracking token is a 48-character random string that is not walkable. Holding the token is
 * the proof of ownership — it is the same proof that lets them read the order's contents,
 * and asking to cancel is a strictly smaller act than reading the name and phone number
 * already on that page.
 *
 * ⚠️ THE WINDOW COMES FROM THE STATUS MACHINE, NOT FROM AN `if` HERE. `canMoveTo` already
 * knows that `ready`, `out_for_delivery` and `completed` cannot be cancel-requested. A
 * second copy of that rule in this controller is how the two drift apart.
 */
final class OrderCancellationController extends Controller
{
    public function __construct(private readonly OrderStatusMachine $machine) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $order = Order::query()->forTracking($token)->first();

        abort_if($order === null, 404, 'Not found.');

        $data = $request->validate([
            // Required, and not for form's sake. She has to decide whether to accept, and
            // "my plans changed" and "I ordered the wrong day" get different answers.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        if ($order->status === OrderStatus::CancelRequested) {
            // Idempotent. Tapping twice on a slow connection is not an error, and it must
            // not overwrite the reason already recorded with a second identical one.
            return ApiResponse::success(
                new OrderResource($order->load('items')),
                'We have your request. The kitchen will be in touch.',
            );
        }

        try {
            $order = $this->machine->requestCancellation(
                $order,
                // Who asked, in her terms. Not a user id: the overwhelming majority of these
                // come from guests with no account, and "Ama Mensah (0244…)" is what she
                // needs when she picks up the phone to answer it.
                requestedBy: $order->contact_name.' ('.$order->contact_phone.')',
                reason: $data['reason'],
            );
        } catch (IllegalTransition) {
            /*
             * Past the point of no return — the food is cooked, on its way, or handed over.
             * A sentence rather than a status code the page would have to interpret, because
             * the only useful next step is a phone call.
             */
            return ApiResponse::error(
                'This order has gone too far to cancel online. Please call the kitchen.',
                422,
            );
        }

        return ApiResponse::success(
            new OrderResource($order->load('items')),
            'Cancellation requested. The kitchen will confirm.',
        );
    }
}
