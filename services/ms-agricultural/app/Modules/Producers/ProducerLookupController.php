<?php

namespace App\Modules\Producers;

use App\Http\Controllers\Controller;
use App\Integrations\Sunat\SunatRucClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProducerLookupController extends Controller
{
    public function show(Request $request, SunatRucClient $sunat): JsonResponse
    {
        $data = $request->validate(['ruc' => ['required', 'string', 'regex:/^\d{11}$/D']]);
        $result = $sunat->consultar($data['ruc']);
        return response()->json(['ruc' => $data['ruc'],
            'resultado' => $result['resultado'] ?? 'pendiente',
            'razon_social' => $result['razon_social'] ?? null,
            'estado' => $result['estado'] ?? null,
            'condicion' => $result['condicion'] ?? null,
        ]);
    }
}
