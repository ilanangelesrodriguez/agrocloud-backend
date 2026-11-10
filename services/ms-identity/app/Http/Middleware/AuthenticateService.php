<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateService
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('identity.service_key');
        abort_unless(strlen($key) >= 32 && hash_equals($key, (string) $request->header('X-Service-Key')), 401, 'Invalid service credentials.');

        return $next($request);
    }
}
