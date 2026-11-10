<?php

namespace App\Modules\Sampling;

use App\Models\Lote;
use App\Models\Muestreo;
use App\Models\SamplingProcedure;
use App\Modules\Catalogs\CatalogService;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SamplingService
{
    public function create(int $lot, array $data): Muestreo
    {
        return DB::transaction(function () use ($lot, $data): Muestreo {
            $record = Lote::lockForUpdate()->findOrFail($lot);
            abort_unless($record->estado === 'RECIBIDO' && $record->recepcion()->exists() && ! $record->muestreos()->exists(), 409, 'Lot is not available for sampling.');
            $procedure = isset($data['procedure_id']) ? SamplingProcedure::where('activo', true)->lockForUpdate()->findOrFail($data['procedure_id']) : null;
            abort_if(! $procedure && ! config('agricultural.manual_sampling_enabled'), 403, 'An authorized procedure is required.');
            $count = (int) $data['tamano_muestra'];
            abort_if($count > ($procedure?->max_frutos ?? config('agricultural.sampling_max_fruits')), 422, 'Sampling exceeds the procedure limit.');
            abort_if($procedure?->producto && $procedure->producto !== $record->producto, 422, 'Procedure is for another product.');
            abort_if($count > config('agricultural.sampling_warning_fruits') && ! filter_var($data['confirmar_carga'] ?? false, FILTER_VALIDATE_BOOL), 422, 'Confirm the high workload.');
            $snapshot = $procedure ? $procedure->only(['nombre', 'version', 'metodo', 'criterio_muestra', 'seleccion_frutos', 'max_frutos', 'tamano_recomendado']) : [];
            if (! $procedure) {
                foreach (['protocolo', 'metodo', 'criterio_muestra', 'seleccion_frutos', 'motivo_excepcion'] as $field) {
                    if (blank($data[$field] ?? null)) {
                        throw ValidationException::withMessages([$field => 'Requerido para una excepcion manual.']);
                    }
                    $snapshot[$field] = $data[$field];
                }
            }
            if ($procedure?->tamano_recomendado && $count !== $procedure->tamano_recomendado && blank($data['motivo_desviacion'] ?? null)) {
                throw ValidationException::withMessages(['motivo_desviacion' => 'Explica la desviacion del procedimiento.']);
            }
            $population = isset($data['poblacion_estimada']) ? (int) $data['poblacion_estimada'] : null;
            abort_if($procedure?->requiere_poblacion && ! $population, 422, 'A documented population is required.');
            abort_if($population && ($count > $population || blank($data['fuente_poblacion'] ?? null)), 422, 'Invalid or undocumented population.');
            $method = app(CatalogService::class)->validate(['metodo' => $procedure?->metodo ?? $data['metodo']], ['metodo' => 'metodo'])['metodo'];
            $sampling = Muestreo::create(['id_lote' => $lot, 'id_inspector' => app(CompanyContext::class)->actor(), 'procedure_id' => $procedure?->getKey(),
                'tamano_muestra' => $count, 'metodo' => $method, 'estado' => 'REGISTRADO', 'observaciones' => $data['observaciones'] ?? null,
                'protocolo' => [...$snapshot, 'modalidad' => $procedure ? 'AUTORIZADO' : 'MANUAL', 'motivo_desviacion' => $data['motivo_desviacion'] ?? null,
                    'poblacion_estimada' => $population, 'fuente_poblacion' => $data['fuente_poblacion'] ?? null]]);
            DB::insert("INSERT INTO frutos_muestra (id_empresa, id_muestreo, numero, codigo, created_at, updated_at)
                SELECT ?, ?, numero, ? || '-F' || LPAD(numero::text, GREATEST(5, LENGTH(numero::text)), '0'), ?, ? FROM generate_series(1, CAST(? AS integer)) AS frutos(numero)",
                [app(CompanyContext::class)->company(), $sampling->getKey(), 'M'.$sampling->getKey(), now(), now(), $count]);
            $record->update(['estado' => 'EN_MUESTREO']);
            Traceability::record($sampling, $lot, 'MUESTREO_REGISTRADO', $sampling->protocolo);

            return $sampling;
        }, 3);
    }
}
