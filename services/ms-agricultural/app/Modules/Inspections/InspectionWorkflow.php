<?php

namespace App\Modules\Inspections;

use App\Models\EvidenciaInspeccion;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

class InspectionWorkflow
{
    public function mutate(int $id, callable $callback, bool $capture = true): mixed
    {
        return DB::transaction(function () use ($id, $callback, $capture): mixed {
            $initial = Inspeccion::with('muestreo')->findOrFail($id);
            $lot = Lote::lockForUpdate()->findOrFail($initial->muestreo->id_lote);
            $inspection = Inspeccion::with(['muestreo', 'evidencias.validaciones', 'hallazgos', 'dictamenes'])->lockForUpdate()->findOrFail($id);
            if ($capture) {
                request()->user()->requireRole('INSPECTOR');
                abort_unless($inspection->estado === 'EN_CAPTURA', 422, 'Inspection is closed.');
                $actor = app(CompanyContext::class)->actor();
                abort_if($inspection->id_inspector !== null && $inspection->id_inspector !== $actor, 403, 'Assigned to another inspector.');
                if ($inspection->id_inspector === null) {
                    $inspection->update(['id_inspector' => $actor]);
                    Traceability::record($inspection, $lot->getKey(), 'INSPECTOR_ASIGNADO', ['id_inspector' => $actor]);
                }
            }

            return $callback($inspection, $lot);
        }, 3);
    }

    public function fingerprint(EvidenciaInspeccion $photo, Inspeccion $inspection): string
    {
        $photo->load('validaciones');
        $inspection->load('hallazgos');

        return hash('sha256', json_encode([
            $photo->estado_ia, $photo->resultado_ia, $photo->version_modelo,
            $photo->validaciones->sortBy('indice_deteccion')->map(fn ($item) => [$item->indice_deteccion, $item->confirmada, $item->observaciones])->values()->all(),
            $inspection->hallazgos->where('id_evidencia', $photo->getKey())->sortBy('id_hallazgo')->map(fn ($item) => [$item->getKey(), $item->clase, $item->naturaleza, $item->observaciones])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function complete(Inspeccion $inspection): bool
    {
        $inspection->load(['evidencias.validaciones', 'hallazgos']);
        $photos = $inspection->evidencias->where('pertinencia', '<>', 'INVALIDA');
        if ($photos->isEmpty()) {
            return false;
        }
        foreach ($photos as $photo) {
            if ($photo->pertinencia !== 'VALIDA' || in_array($photo->estado_ia, ['EN_COLA', 'PROCESANDO'], true)
                || ! $photo->reviewed_at || ! hash_equals((string) $photo->review_fingerprint, $this->fingerprint($photo, $inspection))) {
                return false;
            }
        }

        return ! $inspection->muestreo->frutos()->whereDoesntHave('evidencias', fn ($query) => $query->where('id_inspeccion', $inspection->getKey())->where('pertinencia', '<>', 'INVALIDA'))->exists();
    }
}
