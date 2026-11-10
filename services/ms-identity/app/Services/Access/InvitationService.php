<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Auth\SessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationService
{
    public function send(User $account): void
    {
        DB::transaction(function () use ($account): void {
            $user = User::with(['rol', 'empresa'])->lockForUpdate()->findOrFail($account->getKey());
            abort_unless(! $user->email_verified_at && $user->rol?->estado && $user->empresa?->estado, 422, 'Account is not awaiting activation.');
            $token = Str::random(64);
            $user->forceFill(['invitation_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addHours(72),
                'invitation_attempted_at' => now(), 'invitation_delivery_status' => 'queued'])->save();
            $url = AccountSecurity::url('activate', ['user_id' => $user->getKey(), 'token' => $token]);
            AccountSecurity::mail($user, 'INVITATION', 'Activa tu cuenta AgroCloud', 'Enlace de un solo uso, valido 72 horas: '.$url);
            AccountSecurity::record($user, 'INVITATION_QUEUED');
        }, 3);
    }

    public function activate(int $id, string $token, string $password): void
    {
        DB::transaction(function () use ($id, $token, $password): void {
            $user = User::with(['rol', 'empresa'])->lockForUpdate()->find($id);
            abort_unless($user && $user->invitation_hash && $user->invitation_expires_at?->isFuture()
                && $user->rol?->estado && $user->empresa?->estado
                && hash_equals($user->invitation_hash, hash('sha256', $token)), 410, 'Invitation expired or unavailable.');
            $user->forceFill(['password_hash' => $password, 'invitation_hash' => null, 'invitation_expires_at' => null,
                'email_verified_at' => now(), 'estado' => true])->save();
            app(SessionService::class)->revokeAll($user);
            AccountSecurity::record($user, 'ACCOUNT_ACTIVATED');
        }, 3);
    }
}
