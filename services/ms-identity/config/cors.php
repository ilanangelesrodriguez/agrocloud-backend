<?php

return [
    'paths' => ['api/*', '.well-known/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
    'allowed_origins' => [env('IDENTITY_FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
