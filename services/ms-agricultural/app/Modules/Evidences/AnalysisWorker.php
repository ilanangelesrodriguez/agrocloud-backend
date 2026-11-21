<?php

namespace App\Modules\Evidences;

use App\Integrations\Ai\AiAnalysisClient;
use App\Integrations\Identity\IdentityClient;
use App\Models\EvidenciaInspeccion;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Modules\Inspections\InspectionWorkflow;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AnalysisWorker
{
    public function processOne(): bool
    {
        $job = DB::transaction(function (): ?object {
            $job = DB::table('agricultural_ai_jobs')->whereIn('status', ['PENDING', 'PROCESSING'])->where(fn ($query) => $query->where('attempts', '<', 3)->orWhere('status', 'PROCESSING'))
                ->where('next_attempt_at', '<=', now())->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
                ->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $job) {
                return null;
            }
            $job->lock_token = (string) Str::uuid();
            $job->attempts++;
            DB::table('agricultural_ai_jobs')->where('id', $job->id)->update(['status' => 'PROCESSING', 'attempts' => $job->attempts,
                'stage' => 'CLAIMED', 'started_at' => now(), 'lock_token' => $job->lock_token, 'locked_until' => now()->addMinutes(5), 'updated_at' => now()]);

            return $job;
        });
        if (! $job) {
            return false;
        }
        app(CompanyContext::class)->run((int) $job->id_empresa, (int) $job->actor_id, function () use ($job): void {
            $initial = EvidenciaInspeccion::find($job->id_evidencia);
            if (! $initial) {
                DB::table('agricultural_ai_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)
                    ->update(['status' => 'ERROR', 'stage' => 'FAILED', 'last_error_code' => 'EVIDENCE_NOT_FOUND', 'last_error_at' => now(),
                        'completed_at' => now(), 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);

                return;
            }
            $workflow = app(InspectionWorkflow::class);
            $stage = 'VALIDATING';
            try {
                abort_if($job->attempts > 3, 409, 'Exhausted analysis lease.');
                $photo = $workflow->mutate($initial->id_inspeccion, function (Inspeccion $inspection) use ($job): EvidenciaInspeccion {
                    $photo = $inspection->evidencias->firstWhere('id_evidencia', (int) $job->id_evidencia);
                    abort_unless($inspection->estado === 'EN_CAPTURA' && $inspection->id_inspector === (int) $job->actor_id && $photo && $photo->pertinencia !== 'INVALIDA' && $photo->estado_ia !== 'COMPLETADO', 409);
                    $photo->update(['estado_ia' => 'PROCESANDO', 'review_fingerprint' => null, 'reviewed_at' => null]);

                    return $photo;
                }, false);
                $lot = Lote::findOrFail($initial->inspeccion->muestreo->id_lote);
                $products = array_map(fn (string $value): string => Str::lower($value), config('agricultural.ai_supported_products', []));
                abort_unless(in_array(Str::lower(trim($lot->producto)), $products, true), 422, 'No compatible AI model.');
                $stage = 'QUOTA';
                DB::table('agricultural_ai_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)->update(['stage' => $stage, 'updated_at' => now()]);
                app(IdentityClient::class)->consume((int) $job->id_empresa, 'agri:ai:'.$job->id_empresa.':'.$job->id_evidencia, 'analisis_ia');
                $stage = 'INFERENCE';
                DB::table('agricultural_ai_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)->update(['stage' => $stage, 'updated_at' => now()]);
                $result = app(AiAnalysisClient::class)->analyze($photo, $job->lock_token);
                $stage = 'PERSISTING';
                $workflow->mutate($photo->id_inspeccion, function (Inspeccion $inspection, Lote $lot) use ($job, $result): void {
                    $current = DB::table('agricultural_ai_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)->lockForUpdate()->first();
                    abort_unless($current && $inspection->estado === 'EN_CAPTURA', 409, 'Analysis lease or inspection changed.');
                    $photo = $inspection->evidencias->firstWhere('id_evidencia', (int) $job->id_evidencia);
                    $photo->update(['estado_ia' => 'COMPLETADO', 'resultado_ia' => $result, 'ia_request_id' => $result['request_id'],
                        'version_modelo' => $result['trained_model_id'], 'imagen_detectada_url' => $result['annotated_image_url'] ?? null, 'error_ia' => null,
                        'review_fingerprint' => null, 'reviewed_at' => null]);
                    Traceability::record($photo, $lot->getKey(), 'ANALISIS_COMPLETADO', ['request_id' => $result['request_id'], 'model_id' => $result['trained_model_id']]);
                    DB::table('agricultural_ai_jobs')->where('id', $job->id)->update(['status' => 'COMPLETED', 'stage' => 'COMPLETED', 'completed_at' => now(),
                        'last_error_code' => null, 'last_error_at' => null, 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);
                }, false);
            } catch (\Throwable $error) {
                $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
                $errorCode = $stage.'_'.($status >= 400 && $status <= 599 ? 'HTTP_'.$status : 'UNAVAILABLE');
                logger()->warning('Evidence analysis failed.', ['job_id' => $job->id, 'evidence_id' => $job->id_evidencia,
                    'correlation_id' => $job->lock_token, 'stage' => $stage, 'attempt' => $job->attempts,
                    'error_code' => $errorCode, 'exception_type' => $error::class]);
                $workflow->mutate($initial->id_inspeccion, function (Inspeccion $inspection, Lote $lot) use ($job, $status, $errorCode): void {
                    $current = DB::table('agricultural_ai_jobs')->where('id', $job->id)->where('lock_token', $job->lock_token)->lockForUpdate()->first();
                    if (! $current) {
                        return;
                    }
                    $retry = $job->attempts < 3 && $inspection->estado === 'EN_CAPTURA'
                        && ! in_array($status, [400, 401, 403, 404, 409, 413, 415, 422], true);
                    $photo = $inspection->evidencias->firstWhere('id_evidencia', (int) $job->id_evidencia);
                    if ($photo && $inspection->estado === 'EN_CAPTURA' && $photo->estado_ia !== 'COMPLETADO') {
                        $photo->update(['estado_ia' => $retry ? 'EN_COLA' : 'ERROR', 'error_ia' => $retry
                            ? 'Fallo temporal ('.$errorCode.'). Nuevo intento programado.'
                            : 'No se pudo analizar ('.$errorCode.'). Reintenta o realiza una revisión manual justificada.']);
                        Traceability::record($photo, $lot->getKey(), 'ANALISIS_FALLIDO', ['attempt' => $job->attempts]);
                        if (! $retry) {
                            EventOutbox::add('evidence.analysis_failed', ['lotId' => $lot->getKey(), 'evidenceId' => $photo->getKey(), 'recipientIds' => [(int) $job->actor_id]]);
                        }
                    }
                    DB::table('agricultural_ai_jobs')->where('id', $job->id)->update(['status' => $retry ? 'PENDING' : 'ERROR',
                        'stage' => $retry ? 'RETRYING' : 'FAILED', 'last_error_code' => $errorCode, 'last_error_at' => now(), 'completed_at' => $retry ? null : now(),
                        'next_attempt_at' => now()->addSeconds(30 * $job->attempts), 'lock_token' => null, 'locked_until' => null, 'updated_at' => now()]);
                }, false);
            }
        });

        return true;
    }
}
