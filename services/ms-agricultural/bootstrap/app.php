<?php

use App\Http\Middleware\AuthenticateIdentity;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/discovery.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->appendToGroup('api', NoStore::class);
        $middleware->alias([
            'agricultural.auth' => AuthenticateIdentity::class,
            'agricultural.permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response {
            $id = request()->attributes->get('requestId');
            if (is_string($id)) {
                $response->headers->set('X-Request-Id', $id);
            }

            return $response;
        });
    })->create();
