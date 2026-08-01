<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend is not a BFF. Its axios client is browser-only and the token
    | lives in localStorage, so the browser talks to this API cross-origin and
    | CORS is load-bearing. The framework default would allow '*'; pin it to the
    | one origin that is actually ours instead.
    |
    | supports_credentials stays false on purpose: bearer-token auth needs no
    | credentialed requests, and true alongside a wildcard origin is a hole.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
