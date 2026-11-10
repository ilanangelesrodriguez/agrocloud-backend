<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\AccountSecurity;
use App\Services\Access\EmailChangeService;
use App\Services\Access\InvitationService;
use App\Services\Auth\SessionService;
use App\Services\Auth\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validated();
        $pair = DB::transaction(function () use ($data, $sessions): array {
            $user = User::with(['rol', 'empresa'])->where('correo', $data['correo'])->lockForUpdate()->first();
            if (! $user || ! Hash::check($data['password'], $user->password_hash) || ! $user->isAvailable()) {
                throw ValidationException::withMessages(['correo' => 'Credenciales incorrectas o cuenta no disponible.']);
            }
            $pair = $sessions->start($user);
            $user->forceFill(['ultimo_acceso' => now()])->save();
            AccountSecurity::record($user, 'LOGIN', actor: $user);

            return $pair;
        }, 3);

        return response()->json($pair);
    }

    public function refresh(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'min:64', 'max:128']]);
        $pair = $sessions->refresh($data['refresh_token']);
        if (! $pair) {
            throw new AuthenticationException('Invalid, expired or reused refresh token.');
        }

        return response()->json($pair);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('identity_sessions')->where('id', $request->attributes->get('identity_session_id'))->update(['revoked_at' => now(), 'updated_at' => now()]);
        AccountSecurity::record($request->user(), 'LOGOUT', actor: $request->user());

        return response()->json(['message' => 'Session revoked.']);
    }

    public function logoutAll(Request $request, SessionService $sessions): JsonResponse
    {
        DB::transaction(function () use ($request, $sessions): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->getKey());
            $sessions->revokeAll($user);
            AccountSecurity::record($user, 'LOGOUT_ALL', actor: $user);
        });

        return response()->json(['message' => 'All sessions revoked.']);
    }

    private function email(Request $request): string
    {
        if (is_string($request->input('correo'))) {
            $request->merge(['correo' => mb_strtolower(trim($request->input('correo')))]);
        }

        return $request->validate(['correo' => ['required', 'email', 'max:255']])['correo'];
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $correo = $this->email($request);
        try {
            $user = User::with(['rol', 'empresa'])->where('correo', $correo)->first();
            if ($user?->isAvailable()) {
                Password::sendResetLink(['correo' => $correo]);
            }
        } catch (\Throwable $error) {
            report($error);
        }

        return response()->json(['message' => 'Si la cuenta puede recuperar el acceso, recibira un enlace.']);
    }

    public function resetPassword(Request $request, SessionService $sessions): JsonResponse
    {
        $correo = $this->email($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = DB::transaction(function () use ($correo, $data, $sessions): string {
            $user = User::with(['rol', 'empresa'])->where('correo', $correo)->lockForUpdate()->first();
            if (! $user?->isAvailable()) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset(['correo' => $correo, ...$data], function (User $account, string $password) use ($sessions): void {
                $account->forceFill(['password_hash' => $password, 'pending_email' => null, 'email_change_hash' => null,
                    'email_change_expires_at' => null, 'remember_token' => Str::random(60)])->save();
                $sessions->revokeAll($account);
                AccountSecurity::record($account, 'PASSWORD_RESET');
                AccountSecurity::mail($account, 'PASSWORD_CHANGED', 'Seguridad de AgroCloud', 'Se cambio tu clave. Si no lo autorizaste, contacta al administrador.');
            });
        }, 3);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Enlace invalido, expirado o cuenta no disponible.']);
        }

        return response()->json(['message' => 'Password updated; sign in again.']);
    }

    public function activate(Request $request, InvitationService $invitations): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', 'max:255', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $invitations->activate($data['user_id'], $data['token'], $data['password']);

        return response()->json(['message' => 'Account activated; sign in.']);
    }

    public function requestEmailChange(Request $request, EmailChangeService $changes): JsonResponse
    {
        $correo = $this->email($request);
        $data = $request->validate(['current_password' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($request, $correo, $data, $changes): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->getKey());
            abort_unless(Hash::check($data['current_password'], $user->password_hash), 422, 'Current password is incorrect.');
            $changes->request($user, $correo);
        }, 3);

        return response()->json(['message' => 'Confirmation queued; the current address remains valid until confirmed.']);
    }

    public function confirmEmailChange(Request $request, EmailChangeService $changes): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'token' => ['required', 'string', 'max:128']]);
        $changes->confirm($data['user_id'], $data['token']);

        return response()->json(['message' => 'Email changed; sign in again.']);
    }

    public function keys(TokenService $tokens): JsonResponse
    {
        return response()->json($tokens->jwks());
    }
}
