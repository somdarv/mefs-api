<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | `log` writes the message to the log and sends nothing. It is the default, and
    | `AppServiceProvider` falls back to it whenever the selected driver has no
    | credentials — so a half-configured environment texts nobody rather than
    | texting everybody.
    |
    | ⚠️ A test suite must never text a real customer, and the guarantee is not an
    | env var somebody remembers to set. `phpunit.xml` pins `SMS_DRIVER=log`.
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Whether messages are sent at all
    |--------------------------------------------------------------------------
    |
    | Separate from the driver on purpose. "Stop texting people right now" is a thing
    | you want to be able to do without changing which gateway is configured, and
    | without a deploy.
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | SMSOnlineGH
    |--------------------------------------------------------------------------
    |
    | ⚠️ The request shape in SmsOnlineGhSender is taken from their docs and has never
    | run against the live gateway. See that class before debugging an auth failure.
    |
    */

    'smsonlinegh' => [
        'key' => env('SMSONLINEGH_API_KEY', ''),
        'sender_id' => env('SMSONLINEGH_SENDER_ID', 'Mefs'),
        'base_url' => env('SMSONLINEGH_BASE_URL', 'https://api.smsonlinegh.com'),
        'timeout' => (int) env('SMSONLINEGH_TIMEOUT', 10),
    ],

];
