<?php

namespace App\Modules\Quality;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecisionRequest;
use App\Http\Resources\AgriculturalResource;
use App\Models\DictamenCalidad;
use App\Models\Incidencia;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Modules\Catalogs\CatalogService;
use App\Modules\Inspections\InspectionWorkflow;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;

class QualityController extends Controller
{
    public function store(DecisionRequest $request, int $inspection, InspectionWorkflow $workflow, CatalogService $catalog): AgriculturalResource
    {
        $request->user()->requireRole('SUPERVISOR');
        $data = $catalog->validate($request->validated(), ['categoria' => 'categoria', 'motivo' => 'motivo']);
        $record = $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($request, $data, $workflow): DictamenCalidad {
            abort_unless($record->estado === 'VALIDADA', 422, 'Human validation is required.');
            abort_if($record->id_inspector === $request->user()->id_usuario, 403, 'The supervisor cannot decide their own inspection.');
            $previous = $record->dictamenes->sortByDesc('id_dictamen')->first();
            if ($previous) {
                abort_unless((int) ($data['dictamen_anterior'] ?? 0) === $previous->getKey(), 409, 'The latest decision changed.');
                abort_unless($previous->decision === 'OBSERVADO' && in_array($previous->tipo_observacion, ['DOCUMENTACION', 'CORRECCION_ADMINISTRATIVA'], true), 422, 'Final decisions cannot be overwritten; use the prescribed reinspection flow.');
                abort_if(blank($data['respuesta_observacion'] ?? null), 422, 'Document the correction response.');
            } else {
                abort_if(isset($data['dictamen_anterior']), 409, 'No previous decision exists.');
            }
            $type = $data['decision'] === 'OBSERVADO' ? ($data['tipo_observacion'] ?? 'REINSPECCION') : null;
            $reinspection = $data['decision'] === 'OBSERVADO' && $type === 'REINSPECCION';
            abort_if(! $reinspection && ! $workflow->complete($record), 422, 'Evidence reviews are missing or changed.');
            abort_if($data['decision'] === 'APROBADO' && Incidencia::where('id_lote', $lot->getKey())->whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->exists(), 422, 'Unclosed high/critical incidents block release.');
            $decision = DictamenCalidad::create(['id_lote' => $lot->getKey(), 'id_inspeccion' => $record->getKey(),
                'id_supervisor' => $request->user()->id_usuario, 'decision' => $data['decision'], 'tipo_observacion' => $type,
                'categoria' => $data['categoria'] ?? null, 'motivo' => $data['decision'] === 'APROBADO' ? null : ($data['motivo'] ?? null),
                'observaciones' => $data['observaciones'], 'respuesta_observacion' => $data['respuesta_observacion'] ?? null,
                'reemplaza_dictamen' => $previous?->getKey(), 'protocolo_calidad' => $data['protocolo_calidad']]);
            if ($reinspection) {
                Inspeccion::create(['id_muestreo' => $record->id_muestreo, 'id_inspector' => null, 'solicitada_por' => $request->user()->id_usuario, 'estado' => 'EN_CAPTURA']);
                $record->muestreo->update(['estado' => 'EN_INSPECCION']);
                $lot->update(['estado' => 'EN_INSPECCION']);
            } elseif ($data['decision'] === 'OBSERVADO') {
                $lot->update(['estado' => 'OBSERVADO']);
            } else {
                $record->muestreo->update(['estado' => 'COMPLETADO']);
                $lot->update(['estado' => $data['decision'] === 'APROBADO' ? 'CLASIFICADO' : 'RECHAZADO']);
            }
            Traceability::record($decision, $lot->getKey(), 'DICTAMEN_EMITIDO', ['decision' => $decision->decision, 'replaces' => $previous?->getKey(), 'independent_review' => true]);
            EventOutbox::add('quality.decision.issued', ['lotId' => $lot->getKey(), 'inspectionId' => $record->getKey(), 'decisionId' => $decision->getKey(),
                'decision' => $decision->decision, 'reinspection' => $reinspection, 'recipientPermissions' => $data['decision'] === 'OBSERVADO' ? ['inspecciones.capturar', 'calidad.clasificar'] : ['lotes.ver']]);

            return $decision;
        }, false);

        return new AgriculturalResource($record);
    }
}
