<?php

declare(strict_types=1);

namespace App\Services\Payments;

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
