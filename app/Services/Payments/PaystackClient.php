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
 *   initialize  create a transaction, get a hosted checkout URL
 *   verify      ask what actually happened to a reference
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
     * Start a transaction.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, reason: string, authorization_url?: string, data?: array<string, mixed>}
     */
    public function initialize(
        string $reference,
        int $amountMinor,
        string $email,
        string $currency,
        string $callbackUrl,
        array $metadata = [],
    ): array {
        return $this->call(fn () => $this->http()->post($this->url('/transaction/initialize'), [
            'reference' => $reference,
            'amount' => $amountMinor,
            'email' => $email,
            'currency' => $currency,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]), 'initialize');
    }

    /**
     * ⚠️ THE ONLY THING THAT PROVES A PAYMENT HAPPENED, besides a signed webhook.
     *
     * A browser landing on the callback URL proves nothing — anyone can visit it — so the
     * return journey ends in a call to this, server to server, with our secret key.
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
     * @return array{ok: bool, reason: string, authorization_url?: string, data?: array<string, mixed>}
     */
    private function call(callable $request, string $operation): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        try {
            $response = $request();
        } catch (Throwable $e) {
            // A timeout is NOT a failure of the payment — the transaction may well exist at
            // Paystack. Reported as its own reason so the caller can say "unavailable"
            // rather than "declined", which are different sentences to a customer.
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

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return array_filter([
            'ok' => true,
            'reason' => 'ok',
            'authorization_url' => is_string($data['authorization_url'] ?? null) ? $data['authorization_url'] : null,
            'data' => $data,
        ], fn ($value) => $value !== null);
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
