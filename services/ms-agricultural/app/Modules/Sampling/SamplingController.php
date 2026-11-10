<?php

namespace App\Modules\Sampling;

use App\Http\Controllers\Controller;
use App\Http\Requests\SamplingRequest;
use App\Http\Resources\AgriculturalResource;
use App\Models\Muestreo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SamplingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AgriculturalResource::collection(Muestreo::with('lote')->orderByDesc('id_muestreo')->paginate(25));
    }

    public function store(SamplingRequest $request, int $lot, SamplingService $samplings): AgriculturalResource
    {
        $request->user()->requireRole('INSPECTOR');

        return new AgriculturalResource($samplings->create($lot, $request->validated()));
    }

    public function show(int $sampling): AgriculturalResource
    {
        return new AgriculturalResource(Muestreo::with(['lote', 'inspecciones.dictamenes'])->findOrFail($sampling));
    }

    public function fruits(int $sampling): AnonymousResourceCollection
    {
        return AgriculturalResource::collection(Muestreo::findOrFail($sampling)->frutos()->orderBy('numero')->paginate(50));
    }
}
