<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionService
{
    public function __construct(private TokenService $tokens) {}

    public function start(User $user): array
    {
        $id = (string) Str::uuid();
        $expires = now()->addDays(max(1, min(90, (int) config('identity.refresh_days'))));
        DB::table('identity_sessions')->insert([
            'id' => $id, 'id_usuario' => $user->getKey(), 'auth_version' => $user->auth_version,
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->pair($user, $id);
    }

    private function pair(User $user, string $id): array
    {
        $token = Str::random(80);
        DB::table('identity_refresh_tokens')->insert(['hash' => hash('sha256', $token), 'session_id' => $id, 'created_at' => now()]);

        return ['token_type' => 'Bearer', 'access_token' => $this->tokens->issue($user, $id),
            'expires_in' => $this->tokens->ttl(), 'refresh_token' => $token];
    }

    public function refresh(string $raw): ?array
    {
        return DB::transaction(function () use ($raw): ?array {
            $hash = hash('sha256', $raw);
            $lookup = DB::table('identity_refresh_tokens')->where('hash', $hash)->first();
            if (! $lookup) {
                return null;
            }
            $initial = DB::table('identity_sessions')->where('id', $lookup->session_id)->first();
            if (! $initial) {
                return null;
            }
            $user = User::with(['rol', 'empresa'])->lockForUpdate()->find($initial->id_usuario);
            $session = DB::table('identity_sessions')->where('id', $initial->id)->lockForUpdate()->first();
            $token = DB::table('identity_refresh_tokens')->where('hash', $hash)->lockForUpdate()->first();
            if (! $session || ! $token || ! $user || ! $user->isAvailable() || $session->revoked_at
                || $session->expires_at <= now()->toDateTimeString() || $session->auth_version !== $user->auth_version) {
                return null;
            }
            if ($token->consumed_at) {
                DB::table('identity_sessions')->where('id', $session->id)->update(['revoked_at' => now(), 'updated_at' => now()]);

                return null;
            }
            DB::table('identity_refresh_tokens')->where('hash', $hash)->update(['consumed_at' => now()]);

            return $this->pair($user, $session->id);
        }, 3);
    }

    public function revokeAll(User $user): void
    {
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        DB::table('identity_sessions')->where('id_usuario', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
