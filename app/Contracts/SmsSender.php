<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Sms\SmsResult;

/**
 * Sending one text message.
 *
 * ⚠️ AN INTERFACE SO THE `log` DRIVER CAN STAND IN. A test suite must never text a real
 * customer, and the way that is guaranteed is not "remember to set an env var" — it is that
 * the only implementation bound in local and CI has no network code in it at all.
 *
 * `SMS_DRIVER=log` is the default in `.env.example`, and `config/sms.php` falls back to it
 * whenever credentials are missing. Reaching the real gateway takes a deliberate act.
 */
interface SmsSender
{
    /**
     * @param  string  $to  E.164, always. Normalise with `GhanaPhone` before calling.
     */
    public function send(string $to, string $message): SmsResult;
}
