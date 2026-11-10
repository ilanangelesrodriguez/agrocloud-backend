<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Auth\SessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChangeService
{
    private function ensureUnique(string $correo, int $except): void
    {
        if (User::where('id_usuario', '<>', $except)->where(fn ($query) => $query->where('correo', $correo)->orWhere('pending_email', $correo))->exists()) {
            throw ValidationException::withMessages(['correo' => 'La direccion ya esta registrada o reservada.']);
        }
    }

    public function request(User $user, string $correo): void
    {
        abort_unless($user->isAvailable(), 403);
        $this->ensureUnique($correo, $user->getKey());
        abort_if($correo === $user->correo, 422, 'Email is unchanged.');
        $token = Str::random(64);
        $user->forceFill(['pending_email' => $correo, 'email_change_hash' => hash('sha256', $token),
            'email_change_expires_at' => now()->addHours(24)])->save();
        $url = AccountSecurity::url('confirm-email', ['user_id' => $user->getKey(), 'token' => $token]);
        AccountSecurity::mail($user, 'EMAIL_VERIFICATION', 'Confirma tu correo AgroCloud', 'Enlace valido 24 horas: '.$url, $correo);
        AccountSecurity::record($user, 'EMAIL_CHANGE_REQUESTED', actor: $user);
    }

    public function confirm(int $id, string $token): void
    {
        DB::transaction(function () use ($id, $token): void {
            $user = User::with(['rol', 'empresa'])->lockForUpdate()->find($id);
            abort_unless($user?->isAvailable() && $user->pending_email && $user->email_change_expires_at?->isFuture()
                && hash_equals((string) $user->email_change_hash, hash('sha256', $token)), 410, 'Confirmation expired or unavailable.');
            $this->ensureUnique($user->pending_email, $id);
            $old = $user->correo;
            $user->forceFill(['correo' => $user->pending_email, 'pending_email' => null, 'email_change_hash' => null,
                'email_change_expires_at' => null, 'email_verified_at' => now()])->save();
            app(SessionService::class)->revokeAll($user);
            DB::table('password_reset_tokens')->whereIn('email', [$old, $user->correo])->delete();
            AccountSecurity::record($user, 'EMAIL_CHANGE_CONFIRMED', actor: $user);
            AccountSecurity::mail($user, 'EMAIL_CHANGED', 'Seguridad de AgroCloud', 'Se cambio el correo de tu cuenta. Si no lo autorizaste, contacta al administrador.', $old);
        }, 3);
    }
}
