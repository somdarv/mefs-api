<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MomoNetwork;
use App\Enums\PaymentStatus;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Rules\PhoneNumber;
use App\Services\Payments\MomoInstruction;
use App\Services\Payments\PaymentInitiator;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\PaystackClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paying for an order that already exists, and finding out whether it worked.
 *
 * Both routes are keyed on the tracking token and unauthenticated, for the same reason the
 * tracking page is: the person waiting on the prompt has no account.
 */
final class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentInitiator $initiator,
        private readonly PaystackClient $paystack,
        private readonly PaymentRecorder $recorder,
    ) {}

    /**
     * Send a prompt. Or send another one.
     *
     * Separate from confirm so a customer whose first prompt timed out, or went to a wallet
     * with nothing in it, can try again from the tracking page without rebuilding their
     * basket — which is the common case, not an edge one. It is also the only way to pay an
     * order she entered by hand, where nobody named a wallet at the time.
     *
     * ⚠️ THE NUMBER COMES FROM THIS REQUEST, NOT FROM THE ORDER. Retrying on a different
     * wallet is most of the reason this endpoint exists, so it must be possible to name one —
     * and defaulting to `contact_phone` when none is given would send a prompt to whoever the
     * kitchen rings, who may not be paying.
     */
    public function start(Request $request, string $token): JsonResponse
    {
        $order = $this->find($token);

        if ($order->is_paid) {
            return ApiResponse::success(
                ['payment' => null, 'order' => new OrderResource($order->load('items'))],
                'This order is already paid.',
            );
        }

        $data = $request->validate([
            'momo_phone' => ['nullable', 'string', new PhoneNumber],
            'momo_network' => ['nullable', Rule::enum(MomoNetwork::class)],
        ]);

        $attempt = $this->initiator->begin(
            $order,
            MomoInstruction::from($data['momo_phone'] ?? null, $data['momo_network'] ?? null),
        );

        /*
         * ⚠️ ONE SHAPE FOR BOTH OUTCOMES, AND `payment` IS ALWAYS PRESENT.
         *
         * It used to answer with the attempt at the top level on success and a
         * `{payment: null, …}` envelope on failure, so the client had to read two different
         * objects out of one route. `payment: null` is a first-class answer here — the same
         * one `confirm` gives — and a caller that only ever looks in one place cannot
         * accidentally read a missing key as "nothing to report" (Law 1).
         */
        $payload = [
            'payment' => $attempt->toArray(),
            // Travels so the screen can tell "we need a number from you" apart from "the
            // gateway is down", which are different things to be told. Null on success.
            'reason' => $attempt->wasPrompted() ? null : $attempt->reason,
            'order' => new OrderResource($order->load('items')),
        ];

        // ⚠️ 200 ON A FAILURE TO START, NOT AN ERROR STATUS. No keys configured is the state
        // the system is in today, and it is not a failure of the customer's request — the
        // order stands and she arranges payment herself. Law 7: `unjudged` must not block a
        // sale.
        return $attempt->wasPrompted()
            ? ApiResponse::created($payload, 'Prompt sent')
            : ApiResponse::success($payload, $this->sentenceFor($attempt->reason));
    }

    /**
     * ⚠️ HAS THE PROMPT BEEN APPROVED? THE CUSTOMER'S BROWSER CANNOT ANSWER THAT.
     *
     * Approval happens on the handset, off our wire entirely — the browser sitting on the
     * tracking page never sees it, and anyone can type that URL anyway. So the page asks
     * here, and this asks Paystack — server to server, with our secret key.
     *
     * It exists alongside the webhook rather than instead of it. The webhook is the truth and
     * arrives whether or not the customer's browser is still open; this closes the seconds
     * where they are staring at the page before it lands, and it is the only thing that works
     * at all in an environment Paystack cannot reach — which is every developer's laptop.
     * Both funnel into the same `PaymentRecorder`, so a race between them is a no-op rather
     * than a double credit.
     */
    public function verify(string $token): JsonResponse
    {
        $order = $this->find($token);

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        if ($order->is_paid) {
            return $this->state($order, $payment, 'Paid');
        }

        if ($payment === null || ! $this->paystack->isConfigured()) {
            return $this->state($order, $payment, 'No payment to verify.');
        }

        $result = $this->paystack->verify($payment->reference);
        $status = $result['data']['status'] ?? null;

        if (($result['ok'] ?? false) === true && $status === 'success') {
            $this->recorder->succeeded($payment, $result['data']);
            $order->refresh();
            $payment->refresh();
        }

        /*
         * ⚠️ `abandoned` IS NOT TREATED AS A FAILURE WHILE THE PHONE IS STILL RINGING.
         *
         * A charge Paystack has not seen answered can read as `abandoned` in the window
         * before the customer taps approve. Recording that would tell someone their payment
         * failed while the prompt is still sitting on their screen, and the retry they then
         * start could collide with the approval they were halfway through. Only an explicit
         * `failed`, or an `abandoned` whose prompt window has already run out, closes an
         * attempt from here — everything else waits for the webhook, which knows.
         */
        $expired = ! $payment->isAwaitingPrompt();

        if (($result['ok'] ?? false) === true && ($status === 'failed' || ($status === 'abandoned' && $expired))) {
            $this->recorder->failed(
                $payment,
                $result['data'],
                $status === 'abandoned' ? PaymentStatus::Abandoned : PaymentStatus::Failed,
            );
            $payment->refresh();
        }

        return $this->state($order, $payment, $order->is_paid ? 'Paid' : 'Not paid yet');
    }

    /**
     * Settle a SIMULATED payment, one way or the other.
     *
     * ⚠️ FOUR GUARDS, AND EVERY ONE OF THEM IS LOAD-BEARING. This endpoint marks an order
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

        return $this->state(
            $order,
            $payment->fresh(),
            $order->is_paid ? 'Simulated payment recorded' : 'Simulated payment failed',
        );
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * The order, plus the state of the attempt the customer is waiting on.
     *
     * ⚠️ `attempt` IS WHAT DRIVES THE WAITING SCREEN, and it is server-derived on purpose. It
     * used to be a `?simulate=` query parameter carried in the redirect URL, which stopped
     * being available the moment the redirect did — and a screen whose state lives in the URL
     * loses it on the refresh the customer presses when nothing seems to be happening.
     */
    private function state(Order $order, ?Payment $payment, string $message): JsonResponse
    {
        return ApiResponse::success([
            'paid' => $order->is_paid,
            'attempt' => $this->attemptPayload($payment),
            'order' => new OrderResource($order->load('items')),
        ], $message);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attemptPayload(?Payment $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        return [
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            // "Is the phone still ringing?" — status alone cannot answer it, because an
            // unanswered prompt stays `pending` forever and nothing sweeps it.
            'awaiting_prompt' => $payment->isAwaitingPrompt(),
            'momo_phone' => $payment->momo_phone,
            'momo_network' => $payment->momo_network?->value,
            'network_label' => $payment->momo_network?->label(),
            'expires_at' => $payment->prompt_expires_at?->toIso8601String(),
            'is_simulated' => $payment->is_simulated,
        ];
    }

    /**
     * A sentence per reason, because "online payment is unavailable" is wrong for half of
     * them. Being told the kitchen will call, when what actually happened is that nobody has
     * typed a mobile money number yet, sends the customer to the phone for no reason.
     */
    private function sentenceFor(string $reason): string
    {
        return match ($reason) {
            'momo_number_missing' => 'We need a mobile money number to send the prompt to.',
            'already_paid' => 'This order is already paid.',
            default => 'Online payment is not available for this order.',
        };
    }

    private function find(string $token): Order
    {
        $order = Order::query()->forTracking($token)->first();

        abort_if($order === null, 404, 'Not found.');

        return $order;
    }
}
