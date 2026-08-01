<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SmsSender;
use App\Support\GhanaPhone;
use Illuminate\Console\Command;

/**
 * Send one message to one number, to find out what the gateway actually does.
 *
 * This exists for the ten minutes after `SMSONLINEGH_API_KEY` is first set. The request
 * shape in `SmsOnlineGhSender` was written from documentation and has never met the live
 * gateway; this is how you learn whether the auth header and the payload are right, without
 * placing an order to do it.
 *
 *     php artisan sms:test 0241234567
 *
 * It prints the driver it is about to use, so "it said sent and my phone is silent" is
 * immediately explainable — the log driver says sent too.
 */
final class SendTestSms extends Command
{
    protected $signature = 'sms:test {phone : Ghanaian mobile number} {--message= : Text to send}';

    protected $description = 'Send one SMS through the configured driver, to check it works';

    public function handle(SmsSender $sender): int
    {
        $phone = GhanaPhone::normalise((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('That is not a Ghanaian mobile number. Try 024 123 4567.');

            return self::FAILURE;
        }

        $driver = $sender::class;
        $this->line("Driver: <comment>{$driver}</comment>");

        if (str_contains($driver, 'LogSmsSender')) {
            // The single most likely confusion, said before the result rather than after.
            $this->warn('The log driver sends nothing. Set SMS_DRIVER=smsonlinegh and an API key to send for real.');
        }

        $result = $sender->send(
            $phone,
            (string) ($this->option('message') ?? "Mef's: test message, ignore."),
        );

        $this->newLine();
        $this->line("To:     {$phone}");
        $this->line("Status: <comment>{$result->status}</comment>");
        $this->line("Reason: {$result->reason}");

        if ($result->reference !== null) {
            $this->line("Ref:    {$result->reference}");
        }

        // `unavailable` exits non-zero as well as `refused`: "we could not tell" is not a
        // success, and a script driving this should treat it as work still to do.
        return $result->wasSent() ? self::SUCCESS : self::FAILURE;
    }
}
