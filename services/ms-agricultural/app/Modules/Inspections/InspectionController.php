<?php

namespace App\Modules\Inspections;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalResource;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Models\Muestreo;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class InspectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['estado' => ['nullable', 'string', 'max:40']]);

        return AgriculturalResource::collection(Inspeccion::with('muestreo.lote')->when($data['estado'] ?? null, fn ($query, $status) => $query->where('estado', $status))->orderByDesc('id_inspeccion')->paginate(25));
    }

    public function show(int $inspection): AgriculturalResource
    {
        return new AgriculturalResource(Inspeccion::with(['muestreo.lote', 'evidencias.validaciones', 'hallazgos', 'dictamenes'])->findOrFail($inspection));
    }

    public function store(Request $request, int $sampling): AgriculturalResource
    {
        $request->user()->requireRole('INSPECTOR');
        $record = DB::transaction(function () use ($request, $sampling): Inspeccion {
            $initial = Muestreo::findOrFail($sampling);
            $lot = Lote::lockForUpdate()->findOrFail($initial->id_lote);
            $sample = Muestreo::lockForUpdate()->findOrFail($sampling);
            abort_if($sample->estado === 'COMPLETADO' || in_array($lot->estado, ['ANULADO', 'RECHAZADO', 'CLASIFICADO'], true), 422, 'Sampling is closed.');
            $open = $sample->inspecciones()->where('estado', 'EN_CAPTURA')->lockForUpdate()->first();
            if ($open) {
                abort_if($open->id_inspector !== null && $open->id_inspector !== $request->user()->id_usuario, 403);
                if ($open->id_inspector === null) {
                    $open->update(['id_inspector' => $request->user()->id_usuario]);
                }

                return $open;
            }
            abort_if($sample->inspecciones()->exists(), 422, 'Only a supervisor decision can request reinspection.');
            $inspection = Inspeccion::create(['id_muestreo' => $sampling, 'id_inspector' => $request->user()->id_usuario, 'estado' => 'EN_CAPTURA']);
            $sample->update(['estado' => 'EN_INSPECCION']);
            $lot->update(['estado' => 'EN_INSPECCION']);
            Traceability::record($inspection, $lot->getKey(), 'INSPECCION_INICIADA', ['id_muestreo' => $sampling]);

            return $inspection;
        }, 3);

        return new AgriculturalResource($record);
    }

    public function validateInspection(Request $request, int $inspection, InspectionWorkflow $workflow): AgriculturalResource
    {
        $data = $request->validate(['observaciones' => ['nullable', 'string', 'max:3000']]);
        $record = $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($workflow, $data): Inspeccion {
            abort_unless($workflow->complete($record), 422, 'Every sampled fruit needs relevant, explicitly reviewed evidence with all detections decided.');
            $record->update(['estado' => 'VALIDADA', 'validada_en' => now(), 'observaciones' => $data['observaciones'] ?? null]);
            $record->muestreo->update(['estado' => 'PENDIENTE_DICTAMEN']);
            $lot->update(['estado' => 'PENDIENTE_VALIDACION']);
            Traceability::record($record, $lot->getKey(), 'INSPECCION_VALIDADA', $data);
            EventOutbox::add('inspection.validated', ['lotId' => $lot->getKey(), 'inspectionId' => $record->getKey(), 'recipientPermissions' => ['calidad.clasificar']]);

            return $record;
        });

        return new AgriculturalResource($record);
    }
}
