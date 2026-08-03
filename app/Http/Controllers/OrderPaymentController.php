<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Payments\PaymentInitiator;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\PaystackClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Settle a SIMULATED payment, one way or the other.
     *
     * ⚠️ FOUR GUARDS, AND EVERY ONE OF THEM IS LORD-BEARING. This endpoint marks an order
     * paid without money changing hands, so it must be unreachable by anyone who is not
     * deliberately rehearsing:
     *
     *   1. `payment_mode` must be `simulate` RIGHT NOW. Flipping back to live makes a
     *      half-finished rehearsal un-settleable, which is the correct direction to fail.
     *   2. the payment row must itself be `is_simulated`. A real Paystack attempt can never
     *      be settled through here, whatever the mode says.
     *   3. the reference must match, so it settles the attempt the caller names rather than
     *      "the latest one", which during a rehearsal is ambiguous.
     *   4. it goes through `PaymentRecorder`, the same path as the webhook — including the
     *      amount check and the idempotency lock. A simulation that bypassed the recorder
     *      would rehearse nothing worth rehearsing.
     */
    public function simulate(Request $request, string $token): JsonResponse
    {
        $order = $this->find($token);

        if (SystemSetting::get('payment_mode', 'live') !== 'simulate') {
            return ApiResponse::error('Payment simulation is switched off.', 422);
        }

        $data = $request->validate([
            'reference' => ['required', 'string'],
            'outcome' => ['required', 'in:success,failed'],
        ]);

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->where('reference', $data['reference'])
            ->where('is_simulated', true)
            ->first();

        if ($payment === null) {
            return ApiResponse::error('No simulated payment to settle.', 404);
        }

        if ($data['outcome'] === 'failed') {
            $this->recorder->failed($payment, ['simulated' => true, 'status' => 'failed']);
        } else {
            // Shaped like Paystack's `data` object, because the recorder reads it as one —
            // amount included, so the amount check is exercised rather than sidestepped.
            $this->recorder->succeeded($payment, [
                'simulated' => true,
                'status' => 'success',
                'amount' => $payment->amount,
                'channel' => 'simulated',
                'fees' => 0,
                'paid_at' => now()->toIso8601String(),
            ]);
        }

        $order->refresh();

        return ApiResponse::success([
            'paid' => $order->is_paid,
            'order' => new OrderResource($order->load('items')),
        ], $order->is_paid ? 'Simulated payment recorded' : 'Simulated payment failed');
    }

    private function find(string $token): Order
    {
        $order = Order::query()->forTracking($token)->first();

        abort_if($order === null, 404, 'Not found.');

        return $order;
    }
}
