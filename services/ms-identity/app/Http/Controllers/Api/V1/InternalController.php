<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Empresa;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InternalController extends Controller
{
    public function payment(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $secret = (string) config('identity.billing.event_secret');
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413);
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);
        abort_unless(strlen($secret) >= 32 && hash_equals($expected, (string) $request->header('X-AgroCloud-Signature')), 401, 'Invalid event signature.');
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Invalid JSON event.');
        }
        abort_unless(is_array($payload) && ! array_is_list($payload), 422, 'Event must be a JSON object.');
        $data = Validator::make($payload, [
            'event_id' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::in(['payment.succeeded'])],
            'order_reference' => ['required', 'string', 'max:36'], 'company_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:100', 'max:999900'], 'currency' => ['required', Rule::in(['PEN'])],
            'kind' => ['required', Rule::in(['PLAN', 'RENEWAL', 'USER_ADDON', 'AI_ADDON', 'LOT_ADDON'])],
            'provider_charge_id' => ['required', 'string', 'max:100'],
        ])->validate();
        abort_unless($data['event_id'] === $request->header('X-Event-Id') && $data['event_id'] === 'payment.succeeded:'.$data['order_reference'], 422, 'Event identifiers do not match.');
        // Laravel accepts numeric strings as integers; normalize before strict contract comparisons.
        $data['company_id'] = (int) $data['company_id'];
        $data['amount_cents'] = (int) $data['amount_cents'];
        $applied = $subscriptions->payment($data, hash('sha256', $body));

        return response()->json(['accepted' => true, 'duplicate' => ! $applied]);
    }

    public function introspect(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function recipients(Request $request, int $company): JsonResponse
    {
        Empresa::where('estado', true)->findOrFail($company);
        $data = $request->validate(['permission' => ['required', 'string', 'max:120', 'exists:permiso,codigo']]);
        $users = User::where('id_empresa', $company)->where('estado', true)->whereNull('invitation_hash')->whereNotNull('email_verified_at')
            ->whereHas('rol', fn ($query) => $query->where('estado', true)->whereHas('permisos', fn ($query) => $query->where('permiso.codigo', $data['permission'])->where('permiso.estado', true)))
            ->orderBy('id_usuario')->paginate(100, ['id_usuario', 'id_empresa', 'nombre', 'apellido', 'correo']);

        return response()->json($users);
    }

    public function entitlement(int $company, SubscriptionService $subscriptions): JsonResponse
    {
        Empresa::where('estado', true)->findOrFail($company);

        return response()->json($subscriptions->snapshot($company));
    }

    public function consume(Request $request, int $company, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'string', 'max:100'],
            'recurso' => ['required', Rule::in(['lotes', 'analisis_ia'])], 'cantidad' => ['required', 'integer', 'min:1', 'max:1000000']]);

        return response()->json($subscriptions->consume($company, $data['request_id'], $data['recurso'], (int) $data['cantidad']));
    }

    public function roles(Request $request): JsonResponse
    {
        $roles = Rol::with(['permisos' => fn ($query) => $query->where('permiso.estado', true)])
            ->where('estado', true)->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('codigo', '<>', 'SUPERADMIN'))->get();

        return response()->json(['data' => $roles]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $permissions = $request->user()->isSuperAdmin() ? Permiso::where('estado', true)->get()
            : $request->user()->rol->permisos()->where('permiso.estado', true)->get();

        return response()->json(['data' => $permissions]);
    }
}
