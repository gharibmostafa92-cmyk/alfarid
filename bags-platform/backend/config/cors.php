<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('BACKOFFICE_URL', 'http://localhost:5173'),
        env('POS_URL', 'http://localhost:3002'),
        ...array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Retry-After', 'X-Request-ID'],
    // M12: every non-simple API call (JSON + Authorization) was preceded by
    // an OPTIONS preflight because max_age=0 forbade caching. 2h is safe:
    // origins are an explicit allowlist, not '*'.
    'max_age' => (int) env('CORS_MAX_AGE', 7200),
    'supports_credentials' => false,
];
