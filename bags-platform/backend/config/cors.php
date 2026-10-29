<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('BACKOFFICE_URL', 'http://localhost:5173'),
        ...array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Retry-After', 'X-Request-ID'],
    'max_age' => (int) env('CORS_MAX_AGE', 600),
    'supports_credentials' => false,
];
