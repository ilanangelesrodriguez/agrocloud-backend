<?php

namespace App\Http\Middleware;

use App\Integrations\Identity\IdentityClient;
use App\Shared\Tenancy\CompanyContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            throw new AuthenticationException('Bearer token required.');
        }
        $actor = app(IdentityClient::class)->authenticate($request->bearerToken());
        $request->attributes->set('agricultural_actor', $actor);
        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);

        return app(CompanyContext::class)->run($actor->id_empresa, $actor->id_usuario, fn () => $next($request));
    }
}
