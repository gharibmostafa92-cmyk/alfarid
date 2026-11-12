<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    // L8: localhost development origins are only defaults outside
    // production (see App\Support\CorsOrigins). In production every origin
    // must be set explicitly, or a missing env var silently keeps localhost
    // trusted.
    'allowed_origins' => array_values(array_unique(array_filter([
        ...\App\Support\CorsOrigins::defaults((string) env('APP_ENV', 'local')),
        env('FRONTEND_URL'),
        env('BACKOFFICE_URL'),
        env('POS_URL'),
        ...array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Retry-After', 'X-Request-ID'],
    // Cache preflight OPTIONS responses for 2 hours to reduce latency on repeated authenticated API calls.
    'max_age' => (int) env('CORS_MAX_AGE', 7200),
    'supports_credentials' => false,
];
