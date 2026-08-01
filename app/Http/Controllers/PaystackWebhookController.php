<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ⚠️⚠️ THE ONLY THING THAT PROVES A PAYMENT HAPPENED. ⚠️⚠️
 *
 * ── WHY IT IS OUTSIDE THE AUTH GROUP ──────────────────────────────────────────
 *
 * The gateway is not logged in and never will be. Its credential is the signature, and that
 * is a *stronger* proof than a session: it demonstrates possession of the secret key AND
 * that the body has not been altered in transit.
 *
 * ── THE THREE RULES ───────────────────────────────────────────────────────────
 *
 * 1. **Verify before parsing.** The signature covers the RAW body, so it is checked against
 *    `getContent()` before anything decodes or trusts a byte of it. Verifying a
 *    re-serialised array proves nothing about what arrived.
 *
 * 2. **`hash_equals`, never `===`.** String comparison short-circuits on the first differing
 *    byte, and the timing difference is enough to recover a signature one character at a
 *    time. This is the textbook case for a constant-time compare.
 *
 * 3. **Idempotent, because Paystack retries.** Assume duplicates. The unique index on
 *    `payments.reference` and the row lock in `PaymentRecorder` do the work; there is no
 *    `if (already handled)` here that two concurrent deliveries could both pass (§5.7).
 *
 * ── AND IT ALWAYS RETURNS 200 ─────────────────────────────────────────────────
 *
 * Once the signature checks out, every path answers 200 — including "we have never heard of
 * this reference". A non-2xx makes Paystack retry, and retrying will not teach us about a
 * reference we do not have. Anything unexpected is logged for a human instead of being
 * bounced back at a machine that can only repeat itself.
 */
final class PaystackWebhookController extends Controller
{
    public function __construct(private readonly PaymentRecorder $recorder) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('paystack.secret_key');

        if ($secret === '') {
            // No key means no way to verify, so nothing here can be trusted. Refuse and say
            // so in the log — a webhook endpoint that accepted anything while unconfigured
            // would be an unauthenticated write to the payments table.
            Log::warning('Paystack webhook received but no secret key is configured');

            return ApiResponse::error('Webhook not configured.', 503);
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        if (! $this->signatureIsValid($raw, $signature, $secret)) {
            Log::warning('Paystack webhook signature rejected', ['ip' => $request->ip()]);

            return ApiResponse::error('Invalid signature.', 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return ApiResponse::success(null, 'Ignored: unreadable body');
        }

        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = (string) ($data['reference'] ?? '');

        if ($reference === '') {
            return ApiResponse::success(null, 'Ignored: no reference');
        }

        $payment = Payment::query()->where('reference', $reference)->first();

        if ($payment === null) {
            // Someone else's transaction on a shared Paystack account, or a reference from
            // an environment that no longer exists. 200: retrying cannot help.
            Log::info('Paystack webhook for an unknown reference', ['reference' => $reference, 'event' => $event]);

            return ApiResponse::success(null, 'Ignored: unknown reference');
        }

        $outcome = match ($event) {
            'charge.success' => $this->recorder->succeeded($payment, $data),

            // A failed attempt leaves the ORDER pending — the customer can try again in the
            // next thirty seconds, and a dead sale shown to her would be wrong within a
            // minute. See PaymentRecorder::failed().
            'charge.failed' => $this->recorder->failed($payment, $data),
            'charge.abandoned' => $this->recorder->failed($payment, $data, PaymentStatus::Abandoned),

            default => null,
        };

        if ($outcome === null) {
            // Refunds and disputes arrive here too and are M6's business. Logged rather than
            // silently dropped, so "we never got told" is falsifiable.
            Log::info('Paystack webhook event not handled', ['event' => $event, 'reference' => $reference]);

            return ApiResponse::success(null, "Ignored: {$event}");
        }

        return ApiResponse::success(
            ['outcome' => $outcome->status],
            "Webhook {$event}: {$outcome->status}",
        );
    }

    /**
     * HMAC-SHA512 over the raw body, keyed with the secret key.
     *
     * The length check is not a shortcut — `hash_equals` returns false immediately on a
     * length mismatch anyway — it is there so a missing header cannot reach the compare at
     * all.
     */
    private function signatureIsValid(string $raw, string $signature, string $secret): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $raw, $secret);

        return hash_equals($expected, $signature);
    }
}
