<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * The driver local development and CI use. Writes the message to the log and stops.
 *
 * ⚠️ THIS IS THE DEFAULT, AND THAT IS DELIBERATE. `config/sms.php` falls back here whenever
 * credentials are missing, so a half-configured environment texts nobody rather than texting
 * everybody. A test suite that can reach a real gateway will eventually reach it.
 *
 * It also keeps what it sent, in memory, for the lifetime of the process. That is a
 * development convenience — "what did we just send her?" — and the assertion surface for
 * tests. It is not durable and is not a record of anything.
 */
final class LogSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    private array $sent = [];

    public function send(string $to, string $message): SmsResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        Log::info('SMS (log driver — not sent)', [
            'to' => $to,
            'message' => $message,
        ]);

        return SmsResult::sent('log:'.count($this->sent));
    }

    /** @return list<array{to: string, message: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @return list<array{to: string, message: string}> */
    public function sentTo(string $phone): array
    {
        return array_values(array_filter($this->sent, fn (array $row) => $row['to'] === $phone));
    }

    public function flush(): void
    {
        $this->sent = [];
    }
}
