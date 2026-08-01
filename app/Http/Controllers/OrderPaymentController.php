<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentInitiator;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\PaystackClient;
use Illuminate\Http\JsonResponse;

/**
 * Paying for an order that already exists, and finding out whether it worked.
 *
 * Both routes are keyed on the tracking token and unauthenticated, for the same reason the
 * tracking page is: the person coming back from Paystack has no account.
 */
final class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentInitiator $initiator,
        private readonly PaystackClient $paystack,
        private readonly PaymentRecorder $recorder,
    ) {}

    /**
     * Start (or restart) payment.
     *
     * Separate from confirm so a customer who abandoned Paystack can come back to the
     * tracking page and try again without rebuilding their basket — which is the common
     * case, not an edge one.
     */
    public function start(string $token): JsonResponse
    {
        $order = $this->find($token);

        if ($order->is_paid) {
            return ApiResponse::success(
                ['payment' => null, 'order' => new OrderResource($order->load('items'))],
                'This order is already paid.',
            );
        }

        $attempt = $this->initiator->begin($order);

        if (! $attempt->wasStarted()) {
            // ⚠️ 200, NOT AN ERROR. No keys configured is the state the system is in today,
            // and it is not a failure of the customer's request — the order stands and she
            // arranges payment herself. Law 7: `unjudged` must not block a sale.
            return ApiResponse::success([
                'payment' => null,
                'reason' => $attempt->reason,
                'order' => new OrderResource($order->load('items')),
            ], 'Online payment is not available for this order.');
        }

        return ApiResponse::created($attempt->toArray(), 'Payment started');
    }

    /**
     * ⚠️ THE RETURN JOURNEY. A BROWSER REDIRECT IS NOT PROOF OF PAYMENT.
     *
     * Paystack sends the customer back to `/orders/{token}`, and anyone can type that URL.
     * So the page asks here, and this asks Paystack — server to server, with our secret key
     * — what actually happened.
     *
     * It exists alongside the webhook rather than instead of it. The webhook is the truth
     * and arrives whether or not the customer's browser survives the trip; this closes the
     * few seconds where the customer is looking at the page before the webhook has landed.
     * Both funnel into the same `PaymentRecorder`, so a race between them is a no-op rather
     * than a double credit.
     */
    public function verify(string $token): JsonResponse
    {
        $order = $this->find($token);

        if ($order->is_paid) {
            return ApiResponse::success(
                ['paid' => true, 'order' => new OrderResource($order->load('items'))],
                'Paid',
            );
        }

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        if ($payment === null || ! $this->paystack->isConfigured()) {
            return ApiResponse::success(
                ['paid' => false, 'order' => new OrderResource($order->load('items'))],
                'No payment to verify.',
            );
        }

        $result = $this->paystack->verify($payment->reference);

        if (($result['ok'] ?? false) === true && ($result['data']['status'] ?? null) === 'success') {
            $this->recorder->succeeded($payment, $result['data']);
            $order->refresh();
        }

        return ApiResponse::success([
            'paid' => $order->is_paid,
            'order' => new OrderResource($order->load('items')),
        ], $order->is_paid ? 'Paid' : 'Not paid yet');
    }

    private function find(string $token): Order
    {
        $order = Order::query()->forTracking($token)->first();

        abort_if($order === null, 404, 'Not found.');

        return $order;
    }
}
