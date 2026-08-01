<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One text message, sent off the request thread.
 *
 * ⚠️ QUEUED SO A SLOW GATEWAY CANNOT SLOW A CHECKOUT. The customer has paid; whether their
 * confirmation SMS takes 200ms or nine seconds is not their problem and must not be their
 * wait. It also means a gateway outage delays notifications rather than failing orders.
 *
 * The message text is rendered at DISPATCH time and travels in the payload. An order that
 * changes between dispatch and delivery should still produce the message that was true when
 * the thing happened — a "ready to collect" text that re-reads the order and finds it
 * cancelled would say something nobody ever decided to say.
 */
final class SendSms implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts with a backoff, because `unavailable` means "try again" and a gateway
     * blip is the common case. A `refused` result stops immediately — see `handle()`.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly string $to,
        private readonly string $message,
        /** For the log line only — which order this was about. */
        private readonly ?string $context = null,
    ) {}

    public function handle(SmsSender $sender): void
    {
        $result = $sender->send($this->to, $this->message);

        if ($result->wasSent()) {
            return;
        }

        if ($result->isRetryable()) {
            // Throwing is what puts it back on the queue. Nothing else in the system waits
            // on this, so a failure here is loud in the log and invisible to the customer.
            Log::warning('SMS could not be sent; will retry', [
                'to' => $this->to,
                'context' => $this->context,
                'result' => $result->toArray(),
            ]);

            throw new \RuntimeException("SMS unavailable: {$result->reason}");
        }

        // ⚠️ REFUSED IS TERMINAL. A malformed number does not become valid on the third
        // attempt, and retrying it burns the queue and the log for nothing. Recorded and
        // dropped — deliberately not rethrown.
        Log::warning('SMS refused; not retrying', [
            'to' => $this->to,
            'context' => $this->context,
            'result' => $result->toArray(),
        ]);
    }
}
