<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use App\Services\Access\AccountSecurity;
use App\Services\Access\InvitationService;
use App\Services\Auth\SessionService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    private function target(Request $request, int $id, bool $lock = false): User
    {
        $actor = $request->user();

        return User::with(['rol', 'empresa'])->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('id_empresa', $actor->id_empresa))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        return UserResource::collection(User::with('rol')->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('id_empresa', $actor->id_empresa))->orderBy('id_usuario')->paginate(25));
    }

    public function show(Request $request, int $user): UserResource
    {
        return new UserResource($this->target($request, $user));
    }

    public function store(UserRequest $request, InvitationService $invitations, SubscriptionService $subscriptions): UserResource
    {
        $data = $request->validated();
        $actor = $request->user();
        $companyId = $actor->isSuperAdmin() ? ($data['id_empresa'] ?? null) : $actor->id_empresa;
        abort_unless($companyId && ($actor->isSuperAdmin() || ! isset($data['id_empresa']) || $data['id_empresa'] === $companyId), 403);
        $user = DB::transaction(function () use ($data, $companyId, $actor, $subscriptions, $invitations): User {
            Empresa::where('estado', true)->lockForUpdate()->findOrFail($companyId);
            $role = Rol::where('estado', true)->where('codigo', '<>', 'SUPERADMIN')->findOrFail($data['id_rol']);
            $count = User::where('id_empresa', $companyId)->count();
            if (! ($actor->isSuperAdmin() && $count === 0 && $role->codigo === 'ADMIN')) {
                $subscriptions->ensureUserCapacity($companyId, $count);
            }
            $user = User::create([...$data, 'id_empresa' => $companyId, 'id_rol' => $role->getKey(),
                'password_hash' => Str::random(64), 'estado' => false]);
            $user->forceFill(['invitation_hash' => hash('sha256', Str::random(64))])->save();
            AccountSecurity::record($user, 'USER_CREATED', actor: $actor);
            $invitations->send($user);

            return $user;
        }, 3);

        return new UserResource($user);
    }

    public function update(UserRequest $request, int $user, SessionService $sessions): UserResource
    {
        $target = $this->target($request, $user);
        $data = $request->validated();
        abort_if(isset($data['id_empresa']) && $data['id_empresa'] !== $target->id_empresa, 422, 'Company reassignment is not supported.');
        abort_if(isset($data['correo']) && $data['correo'] !== $target->correo, 422, 'Use the verified self-service email change flow.');
        $result = DB::transaction(function () use ($request, $user, $data, $sessions, $target): User {
            if ($target->id_empresa) {
                Empresa::lockForUpdate()->findOrFail($target->id_empresa);
            }
            $account = $this->target($request, $user, true);
            abort_if($account->isSuperAdmin() && ! $account->is($request->user()), 403);
            if (isset($data['id_rol'])) {
                $role = Rol::where('estado', true)->findOrFail($data['id_rol']);
                abort_if($role->codigo === 'SUPERADMIN' || $account->isSuperAdmin(), 403, 'Platform roles cannot be assigned through this endpoint.');
            }
            if (array_key_exists('estado', $data)) {
                abort_unless($request->user()->hasPermission('usuarios.estado'), 403);
                abort_if($account->is($request->user()) && ! $data['estado'], 422, 'Cannot disable your own account.');
            }
            if ($account->isCompanyAdmin() && ((isset($data['id_rol']) && $data['id_rol'] !== $account->id_rol) || (array_key_exists('estado', $data) && ! $data['estado']))) {
                abort_unless(User::where('id_empresa', $account->id_empresa)->where('estado', true)->whereNull('invitation_hash')->whereNotNull('email_verified_at')->where('id_usuario', '<>', $user)
                    ->whereHas('rol', fn ($query) => $query->where('codigo', 'ADMIN')->where('estado', true))->exists(), 422, 'Cannot remove the last company administrator.');
            }
            $account->fill($data);
            if (array_key_exists('estado', $data) && ! $data['estado']) {
                $account->forceFill(['invitation_hash' => null, 'invitation_expires_at' => null,
                    'pending_email' => null, 'email_change_hash' => null, 'email_change_expires_at' => null]);
            }
            $account->save();
            $sessions->revokeAll($account);
            AccountSecurity::record($account, 'USER_UPDATED', ['fields' => array_keys($data)], $request->user());

            return $account->fresh(['rol']);
        }, 3);

        return new UserResource($result);
    }

    public function invite(Request $request, int $user, InvitationService $invitations): JsonResponse
    {
        $invitations->send($this->target($request, $user));

        return response()->json(['message' => 'Invitation queued.']);
    }

    public function audit(Request $request, int $user): JsonResponse
    {
        $account = $this->target($request, $user);

        return response()->json([
            'events' => DB::table('account_security_events')->where('id_usuario', $account->getKey())->orderByDesc('id')->paginate(25),
            'mail_attempts' => DB::table('security_mail_attempts')->where('id_usuario', $account->getKey())->orderByDesc('id')->limit(25)->get(),
        ]);
    }
}
