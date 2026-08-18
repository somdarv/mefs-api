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
 * ── THE SHAPE, AND HOW IT WAS WRONG ───────────────────────────────────────────
 *
 * ⚠️ THIS POSTED JSON, AND THE `/v5/` API TAKES ONLY FORM DATA. Their own "Request Basics"
 * page is explicit: request data is `application/x-www-form-urlencoded`, key-value pairs
 * separated by ampersands, and JSON is not accepted. This class sent `Http::asJson()` with a
 * nested `messages[].destinations[].to` array invented from a misreading of the SDK docs — a
 * body the gateway could never have parsed. It was never caught because every test fakes the
 * response, and a faked gateway agrees with whatever you send it: `test_the_gateway_driver_
 * reports_a_send` asserted the nested shape and passed, proving only that we are consistent
 * with ourselves.
 *
 * The wire, per their documentation:
 *
 *   POST {base}/v5/message/sms/send
 *   Content-Type: application/x-www-form-urlencoded
 *   key=API_KEY&text=Hello&type=0&sender=SENDER_ID&to=0246314915,233242053072
 *
 * The credential goes in the BODY. Their docs allow three placements — query parameter,
 * body parameter, or an `Authorization: key <API_KEY>` header — and the body is the one their
 * canonical example uses, so it is the one with the least room to be subtly wrong at a seam
 * that has never been exercised. One credential path, not two.
 *
 * ⚠️ STILL NOT VERIFIED AGAINST A LIVE KEY, and one thing in particular is open: this is a
 * RESELLER account, and resellers may be issued their own API host rather than
 * `api.smsonlinegh.com`. That is why `SMSONLINEGH_BASE_URL` is configuration and not a
 * constant — confirm it in the reseller portal before blaming the payload.
 *
 * When the key arrives:
 *   1. Set `SMSONLINEGH_API_KEY`, `SMSONLINEGH_SENDER_ID` and confirm `SMSONLINEGH_BASE_URL`.
 *   2. Set `SMS_DRIVER=smsonlinegh`. Anything else, or a blank key, silently uses the log
 *      driver — see `AppServiceProvider::bindSmsSender()`.
 *   3. Send one message to your own phone with `php artisan sms:test <number>`.
 *   4. If the shape is still wrong, `buildPayload()` and `interpret()` are the only two places
 *      to change. Nothing else in the app knows what SMSOnlineGH looks like.
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
            // ⚠️ `asForm()`, NEVER `asJson()`. The `/v5/` API parses only
            // `application/x-www-form-urlencoded`; JSON is not accepted at all.
            $response = Http::asForm()
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
     * Five flat parameters, exactly as their documented example sends them.
     *
     * ⚠️ NOTHING HERE IS NESTED, and the previous nesting is what made the request
     * unparseable. See the class docblock.
     *
     * @return array<string, string|int>
     */
    private function buildPayload(string $to, string $message): array
    {
        return [
            // The credential travels as a body parameter, which is their canonical example.
            'key' => $this->apiKey,
            'text' => $message,
            // `0` is plain text. Their other types are for flash and unicode messages, which
            // nothing here sends.
            'type' => 0,
            'sender' => $this->senderId,
            // ⚠️ NO LEADING `+`. Storage is E.164 and their examples take `233242053072` or
            // the local `0246314915`; this is the one place the two conventions meet. The
            // parameter is comma-separated for multiple recipients — we send exactly one, so
            // that every result maps to one customer and one failure.
            'to' => ltrim($to, '+'),
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

        /*
         * ⚠️ `data.batch`, NOT `data.messages[0].id`. Their response documentation puts the
         * submission reference on the batch; the old path was part of the same invented
         * nesting as the payload and would have read null forever. Null is survivable here —
         * the message is still sent, we just lose the handle for chasing it later — which is
         * precisely why it would have gone unnoticed.
         */
        $reference = $body['data']['batch'] ?? null;

        return SmsResult::sent(is_string($reference) ? $reference : null);
    }
}
