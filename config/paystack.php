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
    | How long a prompt is worth waiting for
    |--------------------------------------------------------------------------
    |
    | The customer never leaves the shop: a mobile money charge pushes a prompt to
    | their handset and they approve it there. This is how long the tracking page
    | keeps saying "check your phone" before it offers to send another one.
    |
    | ⚠️ IT EXPIRES NOTHING AT PAYSTACK'S END and nothing sweeps the row. A prompt
    | that is never answered leaves the attempt `pending` forever, which is
    | harmless — the ORDER stays pending either way and she can still ring the
    | customer. This only bounds how long a screen tells somebody to keep waiting.
    |
    | There was a `callback_url` here once, for the hosted checkout. It went when
    | the redirect did; nothing lands back on the storefront from Paystack any
    | more, so there is no return URL to get wrong.
    |
    */

    'prompt_ttl' => (int) env('PAYSTACK_PROMPT_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | The address a receipt is addressed to
    |--------------------------------------------------------------------------
    |
    | Paystack requires an email on every transaction and most of her customers
    | have none on file, so `PaymentInitiator` builds one per order.
    |
    | ⚠️ IT HAS TO BE A REAL, PUBLICLY-RESOLVABLE DOMAIN, AND THIS COST A LIVE
    | AFTERNOON. The first version used `@orders.mefs.local`, which is tidy and
    | obviously synthetic and which Paystack refuses outright — every live
    | initialize came back 400 "Invalid Email Address Passed", because `.local`
    | is reserved by RFC 6762. On the storefront that surfaced as "online payment
    | isn't available right now", which pointed at the keys, which were fine.
    |
    | Defaults to the storefront's own host — a domain she owns, which resolves,
    | and which is already correct in every environment that has a FRONTEND_URL.
    | Set PAYSTACK_ORDER_EMAIL_DOMAIN to override it.
    |
    */

    'order_email_domain' => env('PAYSTACK_ORDER_EMAIL_DOMAIN')
        ?: (parse_url((string) env('FRONTEND_URL', ''), PHP_URL_HOST)
            ?: parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),

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
