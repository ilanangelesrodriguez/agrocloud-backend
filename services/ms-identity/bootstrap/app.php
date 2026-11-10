<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/.well-known/jwks.json', [AuthController::class, 'keys'])->middleware('throttle:identity-api');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', NoStore::class);
        $middleware->alias([
            'identity.auth' => AuthenticateToken::class,
            'identity.permission' => PermissionMiddleware::class,
            'identity.service' => AuthenticateService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
