<?php

namespace App\Services\Auth;

use App\Models\Permiso;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use stdClass;

class TokenService
{
    public function publicKey(): string
    {
        $key = @file_get_contents(config('identity.public_key_path'));
        abort_unless(is_string($key) && openssl_pkey_get_public($key) !== false, 503, 'Identity signing keys are not configured.');

        return $key;
    }

    public function issue(User $user, string $sessionId): string
    {
        $key = @file_get_contents(config('identity.private_key_path'));
        abort_unless(is_string($key) && openssl_pkey_get_private($key) !== false, 503, 'Identity signing keys are not configured.');
        $now = time();
        $permissions = $user->isSuperAdmin()
            ? Permiso::where('estado', true)->pluck('codigo')->all()
            : $user->rol->permisos()->where('permiso.estado', true)->pluck('permiso.codigo')->all();

        return JWT::encode([
            'iss' => config('identity.issuer'), 'aud' => config('identity.audience'),
            'iat' => $now, 'nbf' => $now, 'exp' => $now + $this->ttl(),
            'sub' => (string) $user->getKey(), 'sid' => $sessionId, 'jti' => (string) Str::uuid(),
            'ver' => $user->auth_version, 'company_id' => $user->id_empresa,
            'role' => $user->rol->codigo, 'permissions' => $permissions,
        ], $key, 'RS256', config('identity.key_id'));
    }

    public function ttl(): int
    {
        return max(1, min(30, (int) config('identity.access_minutes'))) * 60;
    }

    public function decode(string $token): stdClass
    {
        $publicKey = $this->publicKey();
        try {
            $claims = JWT::decode($token, new Key($publicKey, 'RS256'));
            if (($claims->iss ?? null) !== config('identity.issuer') || ($claims->aud ?? null) !== config('identity.audience')
                || ! is_int($claims->exp ?? null) || ! is_int($claims->iat ?? null) || ! is_int($claims->nbf ?? null)
                || $claims->exp <= time() || $claims->iat > time() || $claims->exp - $claims->iat > $this->ttl()
                || ! is_string($claims->sub ?? null) || ! ctype_digit($claims->sub)
                || ! is_string($claims->sid ?? null) || ! Str::isUuid($claims->sid)
                || ! is_string($claims->jti ?? null) || ! is_int($claims->ver ?? null)) {
                throw new AuthenticationException('Invalid access token.');
            }

            return $claims;
        } catch (\Throwable) {
            throw new AuthenticationException('Invalid or expired access token.');
        }
    }

    public function jwks(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKey()));
        abort_unless(is_array($details) && isset($details['rsa']) && $details['bits'] >= 2048, 503);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => config('identity.key_id'),
            'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
        ]]];
    }
}
