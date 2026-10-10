<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The public API is called from the Aytos24 web frontend on another origin.
| Only the origins listed in CORS_ALLOWED_ORIGINS (comma-separated, exact
| scheme://host:port) may read responses. No cookies or credentials are
| used, so credentials stay disabled. See docs/cors.md.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
)));

return [

    'paths' => ['api/*'],

    // The API is public and read-only.
    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Type'],

    // Lets browser clients read the rate-limit headers (e.g. Retry-After on 429).
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
