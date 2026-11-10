<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'ms-agricultural',
    'apiVersion' => 'v1',
    'description' => 'Gestión de productores, lotes, muestreos e inspecciones.',
    'links' => [
        'health' => '/api/v1/health',
        'ready' => '/api/v1/ready',
        'apiExample' => '/api/v1/lots',
        'documentation' => app()->environment('local') ? '/docs' : null,
        'openapi' => app()->environment('local') ? '/openapi.json' : null,
    ],
]));

if (app()->environment('local')) {
    Route::get('/docs', fn () => response()->file(base_path('docs.html'), [
        'Content-Type' => 'text/html; charset=utf-8',
    ]));
    Route::get('/openapi.json', fn () => response()->file(base_path('openapi.yaml'), [
        'Content-Type' => 'application/json',
    ]));
}
