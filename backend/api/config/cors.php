<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The Naipay API is consumed by first-party clients only: the administrative
| console now, the merchant portal and mobile app later. Origins are therefore
| named explicitly — a wildcard on a financial API would let any site a signed-in
| operator visits issue authenticated requests on their behalf.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        env('ADMIN_APP_URL'),
        env('MERCHANT_APP_URL'),
    ])),

    // Kept empty. Adding a pattern here is how a wildcard creeps back in.
    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-Correlation-Id',
    ],

    // Lets the client read back the correlation ID so a user can quote it to
    // support, and surface rate-limit state before it hits the ceiling.
    'exposed_headers' => [
        'X-Correlation-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 3600,

    // Authentication is by bearer token, not cookies, so no credentialed
    // cross-origin requests are needed.
    'supports_credentials' => false,

];
