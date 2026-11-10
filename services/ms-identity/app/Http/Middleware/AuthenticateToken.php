<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            throw new AuthenticationException('Bearer token required.');
        }
        $claims = app(TokenService::class)->decode($request->bearerToken());
        $user = User::with(['rol.permisos', 'empresa'])->find($claims->sub);
        $session = DB::table('identity_sessions')->where('id', $claims->sid)->where('id_usuario', $claims->sub)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (! $user || ! $user->isAvailable() || ! $session || $claims->ver !== $user->auth_version
            || $session->auth_version !== $user->auth_version || ($claims->company_id ?? null) !== $user->id_empresa
            || ($claims->role ?? null) !== $user->rol->codigo) {
            throw new AuthenticationException('Session revoked or account unavailable.');
        }
        Auth::setUser($user);
        $request->setUserResolver(fn (): User => $user);
        $request->attributes->set('identity_session_id', $session->id);

        return $next($request);
    }
}
