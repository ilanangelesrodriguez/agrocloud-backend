<?php

return [
    'identity_url' => env('IDENTITY_SERVICE_URL', 'http://ms-identity:8000'),
    'identity_key' => env('IDENTITY_SERVICE_API_KEY', ''),
    'ai_url' => env('AI_SERVICE_URL', 'http://ms-ai:8000'),
    'ai_key' => env('AI_SERVICE_API_KEY', ''),
    'notifications_url' => env('NOTIFICATIONS_EVENT_URL', ''),
    'event_secret' => env('AGRICULTURAL_EVENT_SECRET', ''),
    'manual_sampling_enabled' => filter_var(env('MANUAL_SAMPLING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'sampling_max_fruits' => max(1, min(100000, (int) env('SAMPLING_MAX_FRUITS', 100000))),
    'sampling_warning_fruits' => max(1, (int) env('SAMPLING_WARNING_FRUITS', 500)),
];
