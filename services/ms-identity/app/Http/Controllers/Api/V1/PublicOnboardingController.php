<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\SunatRucService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PublicOnboardingController extends Controller
{
    public function lookup(Request $request, SunatRucService $sunat): JsonResponse
    {
        $data = $request->validate(['ruc' => ['required', 'string', 'regex:/^[0-9]{11}$/D']]);
        $result = $sunat->consultar($data['ruc']);
        $public = Arr::only($result, ['ruc', 'resultado', 'razon_social', 'estado', 'condicion']);
        $public['mensaje'] = match ($public['resultado']) {
            'verificado' => 'Datos encontrados. Revisa la información antes de enviarla.',
            'no_encontrado' => 'El proveedor no reconoce el RUC.',
            default => 'Consulta temporalmente indisponible. Puedes continuar con los datos de la empresa.',
        };

        return response()->json(['data' => $public]);
    }

    public function offers(SubscriptionService $subscriptions): JsonResponse
    {
        return response()->json([
            'data' => $subscriptions->publicOffers(),
            'free' => $subscriptions->publicFreePlan(),
        ]);
    }
}
