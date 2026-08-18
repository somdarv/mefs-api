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

        /*
         * ⚠️ THE DEFAULT MUST BE AN APPROVED SENDER ID, AND `Mefs` WAS NOT ONE.
         *
         * `MefsCuisine` is registered with SMSOnlineGH and is the brand's real name;
         * `mefs` is the package and database identifier and is never customer-facing
         * (PRODUCT.md). It is also 11 characters, which is the alphanumeric sender ceiling
         * exactly — there is no room to lengthen it.
         *
         * The default matters more here than defaults usually do, because an unapproved
         * sender fails INVISIBLY: the gateway accepts any sender string at submit time and
         * answers `HSHK_OK` with a batch reference, so `SmsResult` reports `sent` either way.
         * Verified by sending under both `Mefs` and `MefsCuisine` — both were accepted, which
         * is precisely why a wrong value here would never surface as an error.
         */
        'sender_id' => env('SMSONLINEGH_SENDER_ID', 'MefsCuisine'),
        'base_url' => env('SMSONLINEGH_BASE_URL', 'https://api.smsonlinegh.com'),
        'timeout' => (int) env('SMSONLINEGH_TIMEOUT', 10),
    ],

];
