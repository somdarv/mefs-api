<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Keys
    |--------------------------------------------------------------------------
    |
    | ⚠️ THE SECRET KEY IS ALSO THE WEBHOOK SECRET. Paystack signs callbacks with
    | HMAC-SHA512 over the raw request body using it. There is no separate signing
    | secret to configure and no second value to get wrong.
    |
    | Both blank until supplied. `PaymentInitiator` treats "no keys" as UNAVAILABLE
    | rather than as a failure, so the storefront keeps working and orders keep
    | being placed — they simply arrive unpaid, exactly as they do today.
    |
    */

    'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
    'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Where the customer lands afterwards
    |--------------------------------------------------------------------------
    |
    | The order's tracking token is appended. ⚠️ Landing there is NOT proof of
    | payment — anyone can visit that URL — which is why the page shows whatever
    | the webhook has recorded rather than assuming success.
    |
    */

    'callback_url' => env('PAYSTACK_CALLBACK_URL', env('FRONTEND_URL', 'http://localhost:3000').'/orders'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Paystack takes amounts in the currency's minor unit — pesewas for GHS —
    | which is exactly how money is stored here. There is deliberately no
    | conversion anywhere in the payment path.
    |
    */

    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),

];
