<?php

namespace App\Modules\Inspections;

use App\Models\EvidenciaInspeccion;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Collection;
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

        return $this->fingerprintLoaded($photo, $inspection->hallazgos->where('id_evidencia', $photo->getKey()));
    }

    private function fingerprintLoaded(EvidenciaInspeccion $photo, Collection $findings): string
    {
        return hash('sha256', json_encode([
            $photo->estado_ia, $photo->resultado_ia, $photo->version_modelo,
            $photo->validaciones->sortBy('indice_deteccion')->map(fn ($item) => [$item->indice_deteccion, $item->confirmada, $item->observaciones])->values()->all(),
            $findings->sortBy('id_hallazgo')->map(fn ($item) => [$item->getKey(), $item->clase, $item->naturaleza, $item->observaciones])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function reviewProgress(Inspeccion $inspection): array
    {
        $inspection->load(['evidencias.validaciones', 'hallazgos', 'muestreo']);
        $photos = $inspection->evidencias->where('pertinencia', '<>', 'INVALIDA');
        $findings = $inspection->hallazgos->groupBy('id_evidencia');
        $reviewed = $photos->filter(fn ($photo) => $photo->pertinencia === 'VALIDA'
            && ! in_array($photo->estado_ia, ['EN_COLA', 'PROCESANDO'], true) && $photo->reviewed_at
            && hash_equals((string) $photo->review_fingerprint, $this->fingerprintLoaded($photo, $findings->get($photo->getKey(), collect()))));
        $sampled = $inspection->muestreo->frutos()->count();
        $captured = $photos->pluck('id_fruto')->unique()->count();
        $reviewedFruits = $reviewed->pluck('id_fruto')->unique()->count();

        return ['sampled_fruits' => $sampled, 'captured_fruits' => $captured, 'reviewed_fruits' => $reviewedFruits,
            'relevant_photos' => $photos->count(), 'reviewed_photos' => $reviewed->count(),
            'ai_pending' => $photos->whereIn('estado_ia', ['EN_COLA', 'PROCESANDO'])->count(),
            'ai_completed' => $photos->where('estado_ia', 'COMPLETADO')->count(),
            'manual_reviewed' => $reviewed->where('estado_ia', '<>', 'COMPLETADO')->count(),
            'complete' => $sampled > 0 && $captured === $sampled && $reviewedFruits === $sampled && $reviewed->count() === $photos->count()];
    }

    public function complete(Inspeccion $inspection): bool
    {
        return $this->reviewProgress($inspection)['complete'];
    }
}
