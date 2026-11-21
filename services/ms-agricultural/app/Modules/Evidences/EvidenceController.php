<?php

namespace App\Modules\Evidences;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalResource;
use App\Models\EvidenciaInspeccion;
use App\Models\HallazgoManual;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Models\ValidacionDeteccion;
use App\Modules\Catalogs\CatalogService;
use App\Modules\Inspections\InspectionWorkflow;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function store(Request $request, int $inspection, InspectionWorkflow $workflow, EvidenceStorage $storage): AgriculturalResource
    {
        $data = $request->validate(['id_fruto' => ['required', 'integer', 'min:1'],
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072']]);
        $file = $request->file('foto');
        $size = getimagesize($file->getRealPath());
        abort_unless($size && $size[0] * $size[1] <= 16000000, 422, 'Image exceeds the pixel limit.');
        Inspeccion::findOrFail($inspection);
        try {
            $path = $storage->store($file, app(CompanyContext::class)->company(), $inspection);
        } catch (\Exception) {
            abort(503, 'Evidence storage unavailable.');
        }
        try {
            $record = $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($file, $data, $path): EvidenciaInspeccion {
                $fruit = $record->muestreo->frutos()->findOrFail($data['id_fruto']);
                $photo = EvidenciaInspeccion::create(['id_inspeccion' => $record->getKey(), 'id_fruto' => $fruit->getKey(),
                    'file_path' => $path, 'mime' => $file->getMimeType(), 'image_sha256' => hash_file('sha256', $file->getRealPath()),
                    'estado_ia' => 'PENDIENTE', 'pertinencia' => 'PENDIENTE']);
                Traceability::record($photo, $lot->getKey(), 'EVIDENCIA_REGISTRADA', ['id_fruto' => $fruit->getKey(), 'sha256' => $photo->image_sha256]);

                return $photo;
            });
        } catch (\Throwable $error) {
            try {
                $storage->delete($path);
            } catch (\Exception) {
                logger()->warning('Evidence upload cleanup failed.', ['reference' => $path]);
            }
            throw $error;
        }

        return new AgriculturalResource($record);
    }

    public function image(int $evidence, EvidenceStorage $storage): StreamedResponse
    {
        $photo = EvidenciaInspeccion::findOrFail($evidence);
        try {
            $bytes = $storage->read($photo->file_path);
        } catch (\Exception) {
            abort(503, 'Evidence storage unavailable.');
        }
        abort_unless(hash_equals($photo->image_sha256, hash('sha256', $bytes)), 503, 'Evidence integrity check failed.');

        return response()->stream(fn () => print ($bytes), 200, [
            'Content-Type' => $photo->mime,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mutate(int $id, callable $callback): mixed
    {
        $photo = EvidenciaInspeccion::findOrFail($id);

        return app(InspectionWorkflow::class)->mutate($photo->id_inspeccion, function (Inspeccion $inspection, Lote $lot) use ($id, $callback): mixed {
            $photo = $inspection->evidencias->firstWhere('id_evidencia', $id);
            abort_unless($photo, 404);

            return $callback($photo, $inspection, $lot);
        });
    }

    public function analyze(int $evidence): JsonResponse
    {
        $this->mutate($evidence, function (EvidenciaInspeccion $photo, Inspeccion $inspection, Lote $lot): void {
            $this->requireCompatibleModel($lot);
            abort_if($photo->pertinencia === 'INVALIDA' || $photo->estado_ia === 'COMPLETADO', 422, 'Discarded or already analyzed evidence.');
            $job = DB::table('agricultural_ai_jobs')->where('id_empresa', $photo->id_empresa)->where('id_evidencia', $photo->getKey())->lockForUpdate()->first();
            abort_if($job && in_array($job->status, ['PENDING', 'PROCESSING'], true), 409, 'Analysis already queued or running.');
            $this->queuePhoto($photo, $lot);
        });

        return response()->json(['queued' => true], 202);
    }

    private function requireCompatibleModel(Lote $lot): void
    {
        $products = array_map(fn (string $value): string => Str::lower($value), config('agricultural.ai_supported_products', []));
        abort_unless(in_array(Str::lower(trim($lot->producto)), $products, true), 422,
            'No hay un modelo IA compatible con este producto. Continúa con la revisión manual justificada.');
    }

    public function createBatch(Request $request, int $inspection, InspectionWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['evidenceIds' => ['required', 'array', 'min:1', 'max:100'],
            'evidenceIds.*' => ['required', 'integer', 'min:1', 'distinct'], 'idempotencyKey' => ['required', 'uuid']]);
        $ids = array_map('intval', $data['evidenceIds']);
        sort($ids);
        $hash = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
        $batch = $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($data, $ids, $hash): string {
            $company = app(CompanyContext::class)->company();
            $existing = DB::table('agricultural_ai_batches')->where('id_empresa', $company)->where('id_inspeccion', $record->getKey())
                ->where('idempotency_key', $data['idempotencyKey'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->input_hash, $hash), 409, 'Idempotency key used with different evidence.');

                return $existing->id;
            }
            $this->requireCompatibleModel($lot);
            $photos = $record->evidencias->whereIn('id_evidencia', $ids);
            abort_unless($photos->count() === count($ids), 422, 'Every evidence must belong to this inspection.');
            foreach ($photos as $photo) {
                abort_if($photo->pertinencia === 'INVALIDA' || $photo->estado_ia === 'COMPLETADO', 422, 'Discarded or already analyzed evidence.');
                $this->queuePhoto($photo, $lot);
            }
            $batch = (string) Str::uuid();
            DB::table('agricultural_ai_batches')->insert(['id' => $batch, 'id_empresa' => $company, 'id_inspeccion' => $record->getKey(),
                'idempotency_key' => $data['idempotencyKey'], 'input_hash' => $hash, 'evidence_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return $batch;
        });

        return response()->json($this->batchProgress($inspection, $batch), 202);
    }

    private function queuePhoto(EvidenciaInspeccion $photo, Lote $lot): void
    {
        $job = DB::table('agricultural_ai_jobs')->where('id_empresa', $photo->id_empresa)->where('id_evidencia', $photo->getKey())->lockForUpdate()->first();
        if ($job && in_array($job->status, ['PENDING', 'PROCESSING'], true)) {
            return;
        }
        DB::table('agricultural_ai_jobs')->updateOrInsert(['id_empresa' => $photo->id_empresa, 'id_evidencia' => $photo->getKey()],
            ['actor_id' => app(CompanyContext::class)->actor(), 'status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now(),
                'stage' => 'QUEUED', 'last_error_code' => null, 'last_error_at' => null, 'started_at' => null, 'completed_at' => null,
                'lock_token' => null, 'locked_until' => null, 'created_at' => $job?->created_at ?? now(), 'updated_at' => now()]);
        $photo->update(['estado_ia' => 'EN_COLA', 'error_ia' => null, 'review_fingerprint' => null, 'reviewed_at' => null]);
        Traceability::record($photo, $lot->getKey(), 'ANALISIS_SOLICITADO', []);
    }

    public function batchProgress(int $inspection, string $batch): array
    {
        Inspeccion::findOrFail($inspection);
        $record = DB::table('agricultural_ai_batches')->where('id_empresa', app(CompanyContext::class)->company())
            ->where('id_inspeccion', $inspection)->where('id', $batch)->first();
        abort_unless($record, 404);
        $ids = json_decode($record->evidence_ids, true, flags: JSON_THROW_ON_ERROR);
        $jobs = DB::table('agricultural_ai_jobs')->where('id_empresa', $record->id_empresa)->whereIn('id_evidencia', $ids)->get();
        $completed = $jobs->where('status', 'COMPLETED')->count();
        $failed = $jobs->where('status', 'ERROR')->count();
        $cancelled = $jobs->where('status', 'CANCELLED')->count();
        $processing = $jobs->where('status', 'PROCESSING')->count();
        $pending = count($ids) - $completed - $failed - $cancelled - $processing;

        return ['batchId' => $batch, 'status' => $pending + $processing > 0 ? 'PROCESSING' : ($failed + $cancelled > 0 ? 'FAILED' : 'COMPLETED'),
            'total' => count($ids), 'completed' => $completed, 'processing' => $processing, 'pending' => $pending, 'failed' => $failed, 'cancelled' => $cancelled,
            'stalled' => $jobs->contains(fn ($job) => in_array($job->status, ['PENDING', 'PROCESSING'], true) && now()->diffInSeconds($job->updated_at, true) > 300),
            'items' => $jobs->map(fn ($job) => ['evidenceId' => $job->id_evidencia, 'status' => $job->status,
                'stage' => $job->stage, 'attempts' => $job->attempts, 'errorCode' => $job->last_error_code,
                'nextAttemptAt' => $job->next_attempt_at, 'updatedAt' => $job->updated_at])->all()];
    }

    public function retryBatch(int $inspection, string $batch, InspectionWorkflow $workflow): JsonResponse
    {
        $progress = $this->batchProgress($inspection, $batch);
        $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($progress): void {
            $this->requireCompatibleModel($lot);
            foreach ($progress['items'] as $item) {
                if ($item['status'] !== 'ERROR') {
                    continue;
                }
                $photo = $record->evidencias->firstWhere('id_evidencia', (int) $item['evidenceId']);
                if ($photo && $photo->pertinencia !== 'INVALIDA' && $photo->estado_ia !== 'COMPLETADO') {
                    $this->queuePhoto($photo, $lot);
                }
            }
        });

        return response()->json($this->batchProgress($inspection, $batch), 202);
    }

    public function cancelAnalysis(Request $request, int $evidence): AgriculturalResource
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $photo = $this->mutate($evidence, function (EvidenciaInspeccion $photo, Inspeccion $inspection, Lote $lot) use ($data): EvidenciaInspeccion {
            $job = DB::table('agricultural_ai_jobs')->where('id_empresa', $photo->id_empresa)->where('id_evidencia', $photo->getKey())->lockForUpdate()->first();
            abort_unless($job && in_array($job->status, ['PENDING', 'ERROR'], true), 409, 'A running analysis cannot be cancelled.');
            DB::table('agricultural_ai_jobs')->where('id', $job->id)->update(['status' => 'CANCELLED', 'stage' => 'CANCELLED', 'completed_at' => now(),
                'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);
            $photo->update(['estado_ia' => 'ERROR', 'error_ia' => 'Analysis cancelled; manual review requires justification.']);
            Traceability::record($photo, $lot->getKey(), 'ANALISIS_CANCELADO', $data);

            return $photo;
        });

        return new AgriculturalResource($photo);
    }

    public function detection(Request $request, int $evidence, int $index): AgriculturalResource
    {
        $data = $request->validate(['confirmada' => ['required', 'boolean'], 'observaciones' => ['nullable', 'string', 'max:2000']]);
        $record = $this->mutate($evidence, function (EvidenciaInspeccion $photo, Inspeccion $inspection, Lote $lot) use ($index, $data): ValidacionDeteccion {
            abort_unless($photo->pertinencia !== 'INVALIDA' && $photo->estado_ia === 'COMPLETADO', 422);
            $detection = $photo->resultado_ia['detections'][$index] ?? null;
            abort_unless(is_array($detection), 422, 'Unknown detection.');
            $record = ValidacionDeteccion::updateOrCreate(['id_evidencia' => $photo->getKey(), 'indice_deteccion' => $index],
                [...$data, 'confirmada' => filter_var($data['confirmada'], FILTER_VALIDATE_BOOL), 'detection_id' => $detection['id'], 'id_inspector' => app(CompanyContext::class)->actor()]);
            $photo->update(['review_fingerprint' => null, 'reviewed_at' => null]);
            Traceability::record($record, $lot->getKey(), 'DETECCION_VALIDADA', ['index' => $index, ...$data]);

            return $record;
        });

        return new AgriculturalResource($record);
    }

    public function finding(Request $request, int $inspection, InspectionWorkflow $workflow, CatalogService $catalog): AgriculturalResource
    {
        $data = $request->validate(['id_evidencia' => ['required', 'integer', 'min:1'], 'clase' => ['required', 'string', 'max:150'],
            'naturaleza' => ['required', Rule::in(['ORGANISMO', 'ENFERMEDAD', 'DANO_PLAGA', 'DEFECTO_FISICO', 'OTRO'])], 'observaciones' => ['required', 'string', 'max:2000']]);
        $data = $catalog->validate($data, ['clase' => 'defecto']);
        $record = $workflow->mutate($inspection, function (Inspeccion $record, Lote $lot) use ($data): HallazgoManual {
            $photo = $record->evidencias->firstWhere('id_evidencia', (int) $data['id_evidencia']);
            abort_unless($photo && $photo->pertinencia !== 'INVALIDA', 422, 'Evidence must belong to this inspection.');
            $finding = HallazgoManual::create([...$data, 'id_inspeccion' => $record->getKey(), 'id_inspector' => app(CompanyContext::class)->actor()]);
            $photo->update(['review_fingerprint' => null, 'reviewed_at' => null]);
            Traceability::record($finding, $lot->getKey(), 'HALLAZGO_MANUAL', $data);

            return $finding;
        });

        return new AgriculturalResource($record);
    }

    public function review(Request $request, int $evidence): AgriculturalResource
    {
        $data = $request->validate(['revision_completa' => ['required', 'accepted'], 'imagen_pertinente' => ['required', 'accepted'],
            'justificacion' => ['nullable', 'string', 'max:2000'], 'conclusion' => ['sometimes', Rule::in(['CON_HALLAZGOS', 'SIN_HALLAZGOS'])]]);
        $record = $this->mutate($evidence, function (EvidenciaInspeccion $photo, Inspeccion $inspection, Lote $lot) use ($data): EvidenciaInspeccion {
            abort_if($photo->pertinencia === 'INVALIDA' || in_array($photo->estado_ia, ['EN_COLA', 'PROCESANDO'], true), 422, 'Evidence unavailable or analysis pending.');
            abort_if($photo->estado_ia !== 'COMPLETADO' && blank($data['justificacion'] ?? null), 422, 'Justify a manual review without completed AI.');
            foreach (array_keys($photo->resultado_ia['detections'] ?? []) as $index) {
                abort_unless($photo->validaciones->contains('indice_deteccion', $index), 422, 'Decide every detection before review.');
            }
            $found = $photo->validaciones->where('confirmada', true)->isNotEmpty() || $inspection->hallazgos->where('id_evidencia', $photo->getKey())->isNotEmpty();
            $conclusion = $found ? 'CON_HALLAZGOS' : 'SIN_HALLAZGOS';
            abort_if(isset($data['conclusion']) && $data['conclusion'] !== $conclusion, 422, 'Conclusion contradicts human findings.');
            $photo->update(['pertinencia' => 'VALIDA', 'reviewed_at' => now(), 'reviewed_by' => app(CompanyContext::class)->actor(),
                'review_fingerprint' => app(InspectionWorkflow::class)->fingerprint($photo, $inspection)]);
            Traceability::record($photo, $lot->getKey(), 'REVISION_IMAGEN', ['conclusion' => $conclusion, 'justificacion' => $data['justificacion'] ?? null]);

            return $photo;
        });

        return new AgriculturalResource($record);
    }

    public function discard(Request $request, int $evidence): AgriculturalResource
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $record = $this->mutate($evidence, function (EvidenciaInspeccion $photo, Inspeccion $inspection, Lote $lot) use ($data): EvidenciaInspeccion {
            abort_if(in_array($photo->estado_ia, ['EN_COLA', 'PROCESANDO'], true), 409, 'Wait for the current analysis.');
            $photo->update(['pertinencia' => 'INVALIDA', 'review_fingerprint' => null, 'reviewed_at' => null]);
            Traceability::record($photo, $lot->getKey(), 'EVIDENCIA_DESCARTADA', $data);

            return $photo;
        });

        return new AgriculturalResource($record);
    }
}
