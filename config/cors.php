<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The public API is called from the Aytos24 web frontend on another origin.
| Only the origins listed in CORS_ALLOWED_ORIGINS (comma-separated, exact
| scheme://host:port) may read responses. Credentials are allowed so the web
| app can use Sanctum's session cookie; that is only safe because the origin
| list is explicit (never "*"). See docs/cors.md.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PATCH', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    // Lets browser clients read the rate-limit headers (e.g. Retry-After on 429).
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    // Sanctum session cookies for the web app (docs/api/authentication.md).
    'supports_credentials' => true,

];
