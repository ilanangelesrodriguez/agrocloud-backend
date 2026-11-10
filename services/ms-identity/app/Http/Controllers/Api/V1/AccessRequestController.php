<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessRequest;
use App\Models\SolicitudAcceso;
use App\Services\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    public function store(AccessRequest $request): JsonResponse
    {
        SolicitudAcceso::create([...$request->validated(), 'historial' => [['evento' => 'SOLICITADA', 'fecha' => now()->toIso8601String()]]]);

        return response()->json(['message' => 'Access request received.'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()->json(SolicitudAcceso::orderByDesc('id')->paginate(25));
    }

    public function decide(Request $request, int $accessRequest, AccessService $access): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['APROBADA', 'RECHAZADA', 'EN_REVISION'])], 'motivo' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $access->decide($accessRequest, $data['decision'], $data['motivo'], $request->user())]);
    }
}
