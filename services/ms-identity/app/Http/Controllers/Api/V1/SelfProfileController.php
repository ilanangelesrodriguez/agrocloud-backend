<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\AccountSecurity;
use App\Services\Auth\SessionService;
use App\Services\Profile\AvatarStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class SelfProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'apellido' => ['sometimes', 'nullable', 'string', 'max:150'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]*$/D'],
        ]);
        if (! count($data)) throw ValidationException::withMessages(['nombre' => 'Selecciona al menos un campo.']);
        $user = DB::transaction(function () use ($request, $data): User {
            $account = User::lockForUpdate()->findOrFail($request->user()->getKey());
            $account->update($data);
            AccountSecurity::record($account, 'USER_UPDATED', ['fields' => array_keys($data)], $account);
            return $account->fresh(['rol']);
        }, 3);
        return new UserResource($user);
    }

    public function changePassword(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'max:255', Password::min(12)->mixedCase()->numbers()],
        ]);
        DB::transaction(function () use ($request, $data, $sessions): void {
            $account = User::lockForUpdate()->findOrFail($request->user()->getKey());
            if (! Hash::check($data['current_password'], $account->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            if (Hash::check($data['password'], $account->password_hash)) {
                throw ValidationException::withMessages(['password' => 'Utiliza una contraseña diferente.']);
            }
            $account->forceFill(['password_hash' => $data['password']])->save();
            $sessions->revokeAll($account);
            AccountSecurity::record($account, 'PASSWORD_CHANGED', actor: $account);
            AccountSecurity::mail($account, 'PASSWORD_CHANGED', 'Seguridad de AgroCloud', 'Tu contraseña se ha actualizado. Si no realizaste este cambio, recupera tu cuenta.');
        }, 3);
        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión nuevamente.']);
    }

    public function uploadAvatar(Request $request, AvatarStorage $storage): UserResource
    {
        $data = $request->validate(['avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $photo = $storage->upload($data['avatar']);
        try {
            $previous = DB::transaction(function () use ($request, $photo): ?string {
                $account = User::lockForUpdate()->findOrFail($request->user()->getKey());
                $old = $account->foto_perfil_public_id;
                $account->update(['foto_perfil_url' => $photo['url'], 'foto_perfil_public_id' => $photo['public_id']]);
                return $old;
            }, 3);
        } catch (\Throwable $error) {
            $storage->deleteQuietly($photo['public_id']);
            throw $error;
        }
        $storage->deleteQuietly($previous);
        return new UserResource(User::with('rol')->findOrFail($request->user()->getKey()));
    }

    public function deleteAvatar(Request $request, AvatarStorage $storage): JsonResponse
    {
        $old = DB::transaction(function () use ($request): ?string {
            $account = User::lockForUpdate()->findOrFail($request->user()->getKey());
            $previous = $account->foto_perfil_public_id;
            $account->update(['foto_perfil_url' => null, 'foto_perfil_public_id' => null]);
            return $previous;
        }, 3);
        $storage->deleteQuietly($old);
        return response()->json(['deleted' => true]);
    }
}
