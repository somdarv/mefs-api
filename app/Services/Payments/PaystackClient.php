<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\GhanaPhone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only thing in the codebase that knows what Paystack's HTTP API looks like.
 *
 * Two calls are used:
 *
 *   charge   push a mobile money prompt to a handset
 *   verify   ask what actually happened to a reference
 *
 * ⚠️ THERE IS NO `initialize` AND NO HOSTED CHECKOUT. The customer never leaves the shop —
 * they tap pay, a prompt arrives on their phone, and they approve it there. That is a
 * deliberate narrowing: `/transaction/initialize` would also have bought us card payments,
 * and giving those up is the price of never bouncing anyone to another origin mid-order.
 *
 * ⚠️ AMOUNTS ARE MINOR UNITS AT BOTH ENDS. Paystack wants pesewas for GHS, and pesewas is
 * how money is stored here. There is no conversion in the payment path at all, deliberately
 * — the one place that divides by 100 is the display formatter.
 */
final class PaystackClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Push a mobile money prompt to a phone.
     *
     * ⚠️ NOTHING HAS BEEN PAID WHEN THIS RETURNS `ok`. A successful call means Paystack
     * accepted the instruction and the handset is about to buzz — `data.status` comes back
     * `pay_offline`, because the approval happens off our wire entirely. The truth arrives
     * later, by webhook. Treating this response as a payment would mark every order paid the
     * instant it was placed.
     *
     * @param  string  $provider  Paystack's own network code: `mtn` | `vod` | `atl`
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, reason: string, data?: array<string, mixed>}
     */
    public function charge(
        string $reference,
        int $amountMinor,
        string $email,
        string $currency,
        string $phone,
        string $provider,
        array $metadata = [],
    ): array {
        return $this->call(fn () => $this->http()->post($this->url('/charge'), [
            'reference' => $reference,
            'amount' => $amountMinor,
            'email' => $email,
            'currency' => $currency,
            'mobile_money' => [
                'phone' => $phone,
                'provider' => $provider,
            ],
            'metadata' => $metadata,
        ]), 'charge');
    }

    /**
     * Finish a charge that asked for a one-time code.
     *
     * ⚠️ SOME GHANA MOBILE MONEY CHARGES DO NOT PUSH A PROMPT AT ALL. Paystack answers
     * `send_otp`, texts the customer a code itself, and waits here. Until this call is made
     * the charge simply sits — which is exactly what happened before this method existed:
     * `PaymentInitiator` treated every non-`failed` status as "the handset is buzzing", so the
     * customer was told to approve a prompt that was never sent while holding an OTP nothing
     * would ever ask them for.
     *
     * @return array{ok: bool, reason: string, data?: array<string, mixed>}
     */
    public function submitOtp(string $reference, string $otp): array
    {
        return $this->call(fn () => $this->http()->post($this->url('/charge/submit_otp'), [
            'reference' => $reference,
            'otp' => $otp,
        ]), 'submit_otp');
    }

    /**
     * Whose wallet is this, actually?
     *
     * ⚠️ THIS IS THE ONLY HONEST NETWORK DETECTION WE HAVE. `MomoNetwork::forPhone()` reads a
     * prefix table, and Ghana has had number portability since 2011 — the prefix says who
     * ISSUED a number, not who carries it. Resolving against a network either returns that
     * network's registered account name or it does not, which is an answer from the network
     * itself rather than a guess about it.
     *
     * ⚠️ `account_number` IS THE LOCAL `0XXXXXXXXX`, NEVER E.164, AND THAT COST US A RELEASE.
     *
     * This took `$phone` straight through, and every caller hands it E.164 because that is the
     * one storage format in the system. Paystack answers a `+` with a 200 carrying
     * `status: false` and "Account number should be numeric" — so `call()` correctly reported
     * `ok: false`, `MomoController` correctly moved on to the next network, all three correctly
     * failed, and the endpoint correctly answered `unresolved`. Six correct steps on top of one
     * impossible request, reported as "we could not check that number", which is exactly what a
     * gateway outage looks like. Verified against the live account: `+233592123054` is refused
     * and `0592123054` resolves.
     *
     * The conversion lives here rather than at the call site because this class is the only
     * thing that is supposed to know what Paystack's wire looks like — the same reason
     * `MomoNetwork::bankCode()` holds the upper-casing.
     *
     * ⚠️ THE CHARGE STILL SENDS E.164, DELIBERATELY. `mobile_money.phone` accepts it and the
     * live attempts in `payments.payload` prove it. Two endpoints on one gateway want two
     * formats; that is theirs, not ours, and neither call site should have to remember it.
     *
     * @param  string  $phone  E.164, as stored. Converted on the way out.
     * @return array{ok: bool, reason: string, data?: array<string, mixed>}
     */
    public function resolveMomo(string $phone, string $bankCode): array
    {
        $local = GhanaPhone::local($phone);

        if ($local === null) {
            return ['ok' => false, 'reason' => 'unreadable_number'];
        }

        return $this->call(fn () => $this->http()->get($this->url('/bank/resolve'), [
            'account_number' => $local,
            'bank_code' => $bankCode,
        ]), 'resolve');
    }

    /**
     * ⚠️ THE ONLY THING THAT PROVES A PAYMENT HAPPENED, besides a signed webhook.
     *
     * The customer's own browser telling us they approved the prompt proves nothing — they
     * may have declined it, or never seen it — so the tracking page's answer comes from here,
     * server to server, with our secret key.
     *
     * @return array{ok: bool, reason: string, data?: array<string, mixed>}
     */
    public function verify(string $reference): array
    {
        return $this->call(
            fn () => $this->http()->get($this->url('/transaction/verify/'.rawurlencode($reference))),
            'verify',
        );
    }

    /**
     * @param  callable(): Response  $request
     * @return array{ok: bool, reason: string, data?: array<string, mixed>}
     */
    private function call(callable $request, string $operation): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        try {
            $response = $request();
        } catch (Throwable $e) {
            // A timeout is NOT a failure of the payment — the charge may well exist at
            // Paystack, and the customer's phone may already be buzzing. Reported as its own
            // reason so the caller can say "unavailable" rather than "declined", which are
            // different sentences to a customer.
            Log::warning("Paystack {$operation} request failed", ['error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'transport_error'];
        }

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $reason = is_string($body['message'] ?? null) ? $body['message'] : 'http_'.$response->status();

            Log::warning("Paystack {$operation} rejected", [
                'status' => $response->status(),
                'reason' => $reason,
            ]);

            // 5xx is theirs and may succeed on a retry; 4xx is ours and will not. The caller
            // maps these onto unavailable/refused.
            return [
                'ok' => false,
                'reason' => $response->serverError() ? 'gateway_error' : $reason,
            ];
        }

        // ⚠️ A 200 WITH `status: false` IS A FAILURE. Paystack answers 200 and puts the
        // verdict in the body, so reading the HTTP status alone would call every declined
        // transaction a success — the same plausible-success trap as everywhere else.
        if (($body['status'] ?? false) !== true) {
            $reason = is_string($body['message'] ?? null) ? $body['message'] : 'declined';

            return ['ok' => false, 'reason' => $reason];
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'data' => is_array($body['data'] ?? null) ? $body['data'] : [],
        ];
    }

    private function http()
    {
        return Http::asJson()
            ->withToken($this->secretKey)
            ->timeout($this->timeout);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
