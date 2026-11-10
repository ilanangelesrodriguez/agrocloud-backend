<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $id = is_string($incoming) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('requestId', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
