<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessRequest;
use App\Models\SolicitudAcceso;
use App\Services\Access\AccessService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessRequestController extends Controller
{
    public function store(AccessRequest $request, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validated();
        unset($data['privacy_accepted']);
        if (! empty($data['selected_offer_key'])) {
            $offer = collect($subscriptions->publicOffers())->firstWhere('key', $data['selected_offer_key']);
            if (! $offer) {
                throw ValidationException::withMessages(['selected_offer_key' => 'Selecciona un plan público vigente.']);
            }
            $data['selected_offer_snapshot'] = $offer;
        }
        $reference = (string) Str::uuid();
        try {
            SolicitudAcceso::create([
                ...$data,
                'public_reference' => $reference,
                'privacy_accepted_at' => now(),
                'privacy_purpose' => 'business_access_onboarding',
                'historial' => [['evento' => 'SOLICITADA', 'fecha' => now()->toIso8601String()]],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ruc' => 'Ya existe una solicitud sin rechazar para este RUC.']);
        }

        return response()->json(['message' => 'Access request received.', 'reference' => $reference], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()->json(SolicitudAcceso::orderByDesc('id')->paginate(25));
    }

    public function decide(Request $request, int $accessRequest, AccessService $access): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APROBADA', 'RECHAZADA', 'EN_REVISION'])],
            'motivo' => ['required', 'string', 'max:2000'],
            'revision_manual' => ['sometimes', 'boolean'],
            'referencia_verificacion' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $access->decide(
            $accessRequest,
            $data['decision'],
            $data['motivo'],
            $request->user(),
            $request->boolean('revision_manual'),
            $data['referencia_verificacion'] ?? null,
        )]);
    }
}
