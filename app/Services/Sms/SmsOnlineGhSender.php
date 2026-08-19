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
 * ── VERIFIED LIVE, 2026-08-18 ──────────────────────────────────────────────────
 *
 * Run against the real reseller account, not composed from the documentation:
 *
 *   GET  /v5/account/balance     → handshake HSHK_OK
 *   POST /v5/message/sms/send    → sent, with a `data.batch` reference
 *
 * Which settled two open questions. The reseller account uses the standard
 * `api.smsonlinegh.com` — no white-label host — and the response envelope
 * `{handshake:{id,label},data:{…}}` is exactly what `interpret()` already expected, so the
 * parts of this class written from documentation that were right stayed right.
 * `SMSONLINEGH_BASE_URL` remains configuration anyway, because that is a property of the
 * account and not of the code.
 *
 * ⚠️ WHAT CANNOT BE VERIFIED FROM IN HERE IS THE SENDER ID. The gateway validates nothing
 * about `sender` at submit time: sending under an unregistered `Mefs` and an approved
 * `MefsCuisine` both returned HSHK_OK with a batch reference. So `SmsResult` reports `sent`
 * either way and no return value this class produces can tell you the sender is wrong — only
 * the text on a handset can. See the default in `config/sms.php`.
 *
 * If the shape ever needs changing, `buildPayload()` and `interpret()` are the only two
 * places. Nothing else in the app knows what SMSOnlineGH looks like, and that containment is
 * the whole reason for the driver interface — the rest of the system talks to `SmsSender` and
 * cannot tell the difference.
 */
final class SmsOnlineGhSender implements SmsSender
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
        private readonly int $timeout = 10,
    ) {}

    /**
     * Handshake labels meaning "our credentials are wrong", never "this message is wrong".
     *
     * An explicit list rather than a substring test for `AUTH`: a sender-approval rejection
     * would also contain that string, and that one must NOT be retried, because no amount of
     * waiting fixes a sender ID nobody has registered.
     */
    private const CREDENTIAL_FAILURES = ['HSHK_ERR_UA_AUTH'];

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
            /*
             * ⚠️ AN AUTH FAILURE ARRIVES AS A 200, WHICH MAKES THE 401/403 BRANCH ABOVE DEAD
             * CODE ON THIS GATEWAY. Verified against a deliberately revoked key: the send
             * endpoint answers HTTP 200 with
             * `{"handshake":{"id":1203,"label":"HSHK_ERR_UA_AUTH"},"data":null}`.
             *
             * Falling through to the generic `refused` below would mark the message
             * permanently undeliverable and `SendSms` would discard it instead of retrying, so
             * every queued order confirmation and every login code in flight during a key
             * rotation would be destroyed — silently, and for a reason that has nothing to do
             * with the message. That is precisely what the 401/403 branch was written to
             * prevent; it was aimed at a signal this gateway never sends.
             */
            if (in_array($label, self::CREDENTIAL_FAILURES, true)) {
                return SmsResult::unavailable('rejected_credentials');
            }

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
