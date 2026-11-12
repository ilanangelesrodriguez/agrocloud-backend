<?php

return [
    'issuer' => env('IDENTITY_TOKEN_ISSUER', 'agrocloud-identity'),
    'audience' => env('IDENTITY_TOKEN_AUDIENCE', 'agrocloud-api'),
    'key_id' => env('IDENTITY_KEY_ID', 'identity-v1'),
    'private_key_path' => env('IDENTITY_PRIVATE_KEY_PATH', storage_path('app/identity/private.pem')),
    'public_key_path' => env('IDENTITY_PUBLIC_KEY_PATH', storage_path('app/identity/public.pem')),
    'access_minutes' => (int) env('IDENTITY_ACCESS_MINUTES', 15),
    'refresh_days' => (int) env('IDENTITY_REFRESH_DAYS', 14),
    'frontend_url' => env('IDENTITY_FRONTEND_URL', 'http://localhost:3000'),
    'service_key' => env('IDENTITY_SERVICE_API_KEY', ''),
    'privacy_version' => env('IDENTITY_PRIVACY_VERSION', ''),
    'free_plan' => [
        'duration_days' => env('IDENTITY_FREE_PLAN_DAYS'),
        'limits' => [
            'usuarios' => env('IDENTITY_FREE_PLAN_USERS'),
            'lotes' => env('IDENTITY_FREE_PLAN_LOTS'),
            'analisis_ia' => env('IDENTITY_FREE_PLAN_AI'),
        ],
    ],
    'billing' => [
        'url' => env('BILLING_SERVICE_URL', 'http://ms-billing:3000'),
        'api_key' => env('BILLING_SERVICE_API_KEY', ''),
        'event_secret' => env('AGROCLOUD_EVENT_SECRET', ''),
        'offers' => json_decode((env('IDENTITY_BILLING_OFFERS') ?: '{}'), true, 512, JSON_THROW_ON_ERROR),
    ],
    'bootstrap_admin' => [
        'correo' => env('IDENTITY_BOOTSTRAP_EMAIL'),
        'password' => env('IDENTITY_BOOTSTRAP_PASSWORD'),
    ],
];
