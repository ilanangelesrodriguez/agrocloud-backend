<?php

namespace App\Modules\Producers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProducerRequest;
use App\Http\Resources\AgriculturalResource;
use App\Integrations\Sunat\SunatRucClient;
use App\Models\Productor;
use App\Shared\Events\Traceability;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProducerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AgriculturalResource::collection(Productor::orderBy('nombre_razon_social')->paginate(25));
    }

    public function show(int $producer): AgriculturalResource
    {
        return new AgriculturalResource(Productor::findOrFail($producer));
    }

    public function store(ProducerRequest $request): AgriculturalResource
    {
        $data = app(\App\Support\UbigeoCatalog::class)->normalize($request->validated());
        $data['sunat_verificacion'] = app(SunatRucClient::class)->verificar($data['ruc'], $data['nombre_razon_social']);
        abort_if(Productor::where('ruc', $data['ruc'])->exists(), 422, 'Producer RUC already exists in this company.');
        $record = DB::transaction(function () use ($data): Productor {
            $record = Productor::create($data);
            Traceability::record($record, null, 'PRODUCTOR_CREADO', $record->only(['ruc', 'nombre_razon_social']));

            return $record;
        });

        return new AgriculturalResource($record);
    }

    public function update(ProducerRequest $request, int $producer): AgriculturalResource
    {
        $data = app(\App\Support\UbigeoCatalog::class)->normalize($request->validated());
        $data['sunat_verificacion'] = app(SunatRucClient::class)->verificar($data['ruc'], $data['nombre_razon_social']);
        abort_if(Productor::where('ruc', $data['ruc'])->where('id_productor', '<>', $producer)->exists(), 422);

        return new AgriculturalResource(DB::transaction(function () use ($producer, $data): Productor {
            $record = Productor::lockForUpdate()->findOrFail($producer);
            $record->update($data);
            Traceability::record($record, null, 'PRODUCTOR_ACTUALIZADO', ['fields' => array_keys($data)]);

            return $record;
        }));
    }
}
