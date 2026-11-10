<?php

return [
    'paths' => ['api/*'], 'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'OPTIONS'],
    'allowed_origins' => [env('AGRICULTURAL_FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'Idempotency-Key'],
    'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => false,
];
