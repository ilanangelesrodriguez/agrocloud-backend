<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'ms-identity',
    'apiVersion' => 'v1',
    'description' => 'Autenticación, gestión de empresas y suscripciones.',
    'links' => [
        'health' => '/api/v1/health',
        'ready' => '/api/v1/ready',
        'apiExample' => '/api/v1/auth/me',
        'documentation' => app()->environment('local') ? '/docs' : null,
        'openapi' => app()->environment('local') ? '/openapi.json' : null,
    ],
]));

if (app()->environment('local')) {
    Route::get('/docs', function () {
        $methods = config('api_docs.try_it_out') === true
            ? '["get","post","put","patch","delete"]'
            : '[]';

        return response(str_replace('__SWAGGER_SUBMIT_METHODS__', $methods, file_get_contents(base_path('docs.html'))), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    });
    Route::get('/openapi.json', function () {
        $contract = json_decode(file_get_contents(base_path('openapi.yaml')), false, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys(get_object_vars($contract->paths)) as $path) {
            if (str_starts_with($path, '/internal/')) {
                unset($contract->paths->{$path});
            }
        }

        $contract->tags = array_values(array_filter($contract->tags, fn ($tag) => $tag->name !== 'Operaciones internas'));
        foreach ($contract->components->securitySchemes ?? [] as $name => $scheme) {
            if ($scheme->type === 'apiKey') {
                unset($contract->components->securitySchemes->{$name});
            }
        }

        return response()->json($contract)->header('Cache-Control', 'no-store');
    });
}
