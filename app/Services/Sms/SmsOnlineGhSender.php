<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMSOnlineGH.
 *
 * ⚠️⚠️ THE REQUEST SHAPE BELOW IS UNCONFIRMED. ⚠️⚠️
 *
 * The endpoint path, the auth header format and the response keys are taken from their
 * public documentation and have **never been run against the live gateway**, because no API
 * key has been supplied. Everything around this class is finished and tested; this one
 * method is the seam where reality gets to disagree.
 *
 * When the key arrives:
 *   1. Set `SMSONLINEGH_API_KEY` and confirm `SMSONLINEGH_BASE_URL`.
 *   2. Send one message to your own phone with `php artisan sms:test <number>`.
 *   3. If the shape is wrong, `buildPayload()` and `interpret()` are the only two places to
 *      change. Nothing else in the app knows what SMSOnlineGH looks like.
 *
 * That containment is the whole reason for the driver interface. The rest of the system
 * talks to `SmsSender` and cannot tell the difference.
 */
final class SmsOnlineGhSender implements SmsSender
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
        private readonly int $timeout = 10,
    ) {}

    public function send(string $to, string $message): SmsResult
    {
        // Belt and braces. `config/sms.php` will not select this driver without a key, but a
        // driver that would happily POST an empty credential is one bad merge from doing it.
        if ($this->apiKey === '') {
            return SmsResult::unavailable('no_api_key');
        }

        try {
            $response = Http::asJson()
                ->withHeaders([
                    // ⚠️ UNCONFIRMED. Their docs show a `key` header; some of their examples
                    // show `Authorization: key <api-key>`. If auth fails with a valid key,
                    // this line is the first thing to try changing.
                    'Authorization' => 'key '.$this->apiKey,
                ])
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/v5/message/sms/send', $this->buildPayload($to, $message));
        } catch (Throwable $e) {
            // A timeout or a DNS failure is UNAVAILABLE, never refused: the message may
            // well have been delivered, and marking it permanently failed would stop a
            // retry that could still work.
            Log::warning('SMSOnlineGH request failed', ['to' => $to, 'error' => $e->getMessage()]);

            return SmsResult::unavailable('transport_error');
        }

        return $this->interpret($response->status(), $response->json() ?? []);
    }

    /**
     * ⚠️ UNCONFIRMED SHAPE. See the class docblock.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $to, string $message): array
    {
        return [
            'messages' => [[
                // They expect the number without a leading `+`, per their examples. We store
                // E.164 with one, so this is the only place the two conventions meet.
                'text' => $message,
                'type' => 0,
                'sender' => $this->senderId,
                'destinations' => [['to' => ltrim($to, '+')]],
            ]],
        ];
    }

    /**
     * Turn their response into our three states.
     *
     * ⚠️ THE 4xx/5xx SPLIT IS THE LOAD-BEARING PART, and it survives whatever their JSON
     * turns out to look like. A 4xx is us being wrong — a bad number, a bad key — and
     * retrying repeats the mistake. A 5xx is them, and retrying is the correct response. Get
     * that backwards and either a broken gateway looks like a thousand bad phone numbers, or
     * one malformed number retries forever.
     *
     * @param  array<string, mixed>  $body
     */
    private function interpret(int $status, array $body): SmsResult
    {
        if ($status >= 500) {
            return SmsResult::unavailable('gateway_error_'.$status);
        }

        if ($status === 401 || $status === 403) {
            // Our credentials, not their outage — but still unavailable rather than refused:
            // nothing about this message is wrong, and it should go out once the key is
            // fixed rather than being marked permanently undeliverable.
            return SmsResult::unavailable('rejected_credentials');
        }

        if ($status >= 400) {
            $reason = is_string($body['message'] ?? null) ? $body['message'] : 'rejected_'.$status;

            return SmsResult::refused($reason);
        }

        // ⚠️ A 200 IS NOT ALWAYS AN ACCEPTANCE. Gateways in this class habitually return 200
        // with a failure code in the body, and taking the status alone would report every
        // rejected message as sent — the exact "plausible success" failure this codebase
        // keeps guarding against.
        $handshake = $body['handshake'] ?? null;
        $label = is_array($handshake) ? ($handshake['label'] ?? null) : null;

        if (is_string($label) && $label !== 'HSHK_OK') {
            return SmsResult::refused((string) $label);
        }

        $reference = $body['data']['messages'][0]['id'] ?? null;

        return SmsResult::sent(is_string($reference) ? $reference : null);
    }
}
