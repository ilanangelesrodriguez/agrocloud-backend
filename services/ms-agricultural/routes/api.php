<?php

use App\Modules\Catalogs\CatalogController;
use App\Modules\Evidences\EvidenceController;
use App\Modules\Incidents\IncidentController;
use App\Modules\Inspections\InspectionController;
use App\Modules\Lots\LotController;
use App\Modules\Producers\ProducerController;
use App\Modules\Producers\ProducerLookupController;
use App\Http\Controllers\UbigeoController;
use App\Modules\Quality\QualityController;
use App\Modules\Receptions\ReceptionController;
use App\Modules\Reports\AiReportController;
use App\Modules\Reports\ReportController;
use App\Modules\Sampling\SamplingController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/health', fn (): JsonResponse => response()->json(['status' => 'ok', 'service' => 'ms-agricultural']))->name('health');
Route::get('/ready', function (): JsonResponse {
    try {
        DB::select('SELECT 1');
        $schema = Schema::hasTable('agricultural_operations') && Schema::hasTable('agricultural_ai_jobs')
            && Schema::hasTable('agricultural_ai_batches') && Schema::hasColumn('agricultural_ai_jobs', 'stage') && Schema::hasTable('dictamen_calidad');
        $identity = strlen((string) config('agricultural.identity_key')) >= 32 && filter_var(config('agricultural.identity_url'), FILTER_VALIDATE_URL);
        $ready = $schema && $identity;

        return response()->json(['ready' => (bool) $ready, 'database' => true, 'schema' => $schema, 'identity_configured' => (bool) $identity, 'upstreams_checked' => false], $ready ? 200 : 503);
    } catch (Throwable) {
        return response()->json(['ready' => false], 503);
    }
})->name('ready');

Route::middleware(['throttle:agricultural-public', 'agricultural.auth', 'throttle:agricultural-api'])->group(function (): void {
    Route::get('/geo', [UbigeoController::class, 'index']);
    Route::get('/producers/ruc-lookup', [ProducerLookupController::class, 'show'])->middleware('agricultural.permission:productores.ver');
    Route::get('/producers', [ProducerController::class, 'index'])->middleware('agricultural.permission:productores.ver');
    Route::get('/producers/{producer}', [ProducerController::class, 'show'])->whereNumber('producer')->middleware('agricultural.permission:productores.ver');
    Route::post('/producers', [ProducerController::class, 'store'])->middleware('agricultural.permission:productores.crear');
    Route::put('/producers/{producer}', [ProducerController::class, 'update'])->whereNumber('producer')->middleware('agricultural.permission:productores.editar');
    Route::get('/lots', [LotController::class, 'index'])->middleware('agricultural.permission:lotes.ver');
    Route::post('/lots', [LotController::class, 'store'])->middleware('agricultural.permission:lotes.crear');
    Route::get('/lots/{lot}', [LotController::class, 'show'])->whereNumber('lot')->middleware('agricultural.permission:lotes.ver');
    Route::post('/lots/{lot}/cancel', [LotController::class, 'cancel'])->whereNumber('lot')->middleware('agricultural.permission:lotes.eliminar');
    Route::get('/lots/{lot}/traceability', [LotController::class, 'traceability'])->whereNumber('lot')->middleware('agricultural.permission:trazabilidad.ver');
    Route::post('/lots/{lot}/reception', [ReceptionController::class, 'store'])->whereNumber('lot')->middleware('agricultural.permission:recepciones.crear');
    Route::get('/samplings', [SamplingController::class, 'index'])->middleware('agricultural.permission:muestreos.ver');
    Route::post('/lots/{lot}/samplings', [SamplingController::class, 'store'])->whereNumber('lot')->middleware('agricultural.permission:muestreos.crear');
    Route::get('/samplings/{sampling}', [SamplingController::class, 'show'])->whereNumber('sampling')->middleware('agricultural.permission:muestreos.ver');
    Route::get('/samplings/{sampling}/fruits', [SamplingController::class, 'fruits'])->whereNumber('sampling')->middleware('agricultural.permission:muestreos.ver');
    Route::get('/inspections', [InspectionController::class, 'index'])->middleware('agricultural.permission:inspecciones.ver');
    Route::get('/inspections/{inspection}', [InspectionController::class, 'show'])->whereNumber('inspection')->middleware('agricultural.permission:inspecciones.ver');
    Route::post('/samplings/{sampling}/inspections', [InspectionController::class, 'store'])->whereNumber('sampling')->middleware('agricultural.permission:inspecciones.crear');
    Route::post('/inspections/{inspection}/evidences', [EvidenceController::class, 'store'])->whereNumber('inspection')->middleware('agricultural.permission:inspecciones.capturar');
    Route::post('/inspections/{inspection}/analyses', [EvidenceController::class, 'createBatch'])->whereNumber('inspection')->middleware('agricultural.permission:inspecciones.analizar');
    Route::get('/inspections/{inspection}/analyses/{batch}', [EvidenceController::class, 'batchProgress'])->whereNumber('inspection')->whereUuid('batch')->middleware('agricultural.permission:inspecciones.ver');
    Route::post('/inspections/{inspection}/analyses/{batch}/retry', [EvidenceController::class, 'retryBatch'])->whereNumber('inspection')->whereUuid('batch')->middleware('agricultural.permission:inspecciones.analizar');
    Route::get('/evidences/{evidence}/image', [EvidenceController::class, 'image'])->whereNumber('evidence')->middleware('agricultural.permission:inspecciones.ver');
    Route::post('/evidences/{evidence}/analysis/cancel', [EvidenceController::class, 'cancelAnalysis'])->whereNumber('evidence')->middleware('agricultural.permission:inspecciones.analizar');
    Route::post('/evidences/{evidence}/analyze', [EvidenceController::class, 'analyze'])->whereNumber('evidence')->middleware('agricultural.permission:inspecciones.analizar');
    Route::patch('/evidences/{evidence}/detections/{index}', [EvidenceController::class, 'detection'])->whereNumber(['evidence', 'index'])->middleware('agricultural.permission:inspecciones.validar');
    Route::post('/inspections/{inspection}/findings', [EvidenceController::class, 'finding'])->whereNumber('inspection')->middleware('agricultural.permission:inspecciones.validar');
    Route::post('/evidences/{evidence}/review', [EvidenceController::class, 'review'])->whereNumber('evidence')->middleware('agricultural.permission:inspecciones.validar');
    Route::post('/evidences/{evidence}/discard', [EvidenceController::class, 'discard'])->whereNumber('evidence')->middleware('agricultural.permission:inspecciones.validar');
    Route::post('/inspections/{inspection}/validate', [InspectionController::class, 'validateInspection'])->whereNumber('inspection')->middleware('agricultural.permission:inspecciones.validar');
    Route::post('/inspections/{inspection}/decisions', [QualityController::class, 'store'])->whereNumber('inspection')->middleware('agricultural.permission:calidad.clasificar');
    Route::get('/incident-responsibles', [IncidentController::class, 'responsibles'])->middleware('agricultural.permission:incidencias.crear');
    Route::get('/incidents/{incident}/history', [IncidentController::class, 'history'])->whereNumber('incident')->middleware('agricultural.permission:incidencias.ver');
    Route::get('/lots/{lot}/incident-evidences', [IncidentController::class, 'evidences'])->whereNumber('lot')->middleware('agricultural.permission:incidencias.ver');
    Route::get('/production/lots', [LotController::class, 'released'])->middleware('agricultural.permission:produccion.ver');
    Route::get('/setup', [ReportController::class, 'setup'])->middleware('agricultural.permission:empresa.editar');
    Route::get('/reports/producers', [ReportController::class, 'producers'])->middleware('agricultural.permission:reportes.operativos');
    Route::get('/reports/ai/producers', [AiReportController::class, 'producers'])->middleware('agricultural.permission:ia.reportes.ver');
    Route::get('/reports/ai/{section}', [AiReportController::class, 'show'])->where('section', 'overview|classes|daily|models|validation')->middleware('agricultural.permission:ia.reportes.ver');
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/incidents', [IncidentController::class, 'index'])->middleware('agricultural.permission:incidencias.ver');
    Route::get('/lots/{lot}/incidents', [IncidentController::class, 'index'])->whereNumber('lot')->middleware('agricultural.permission:incidencias.ver');
    Route::post('/lots/{lot}/incidents', [IncidentController::class, 'store'])->whereNumber('lot')->middleware('agricultural.permission:incidencias.crear');
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident')->middleware('agricultural.permission:incidencias.ver');
    Route::get('/catalogs/admin', [CatalogController::class, 'admin'])->middleware('agricultural.permission:empresa.editar');
    Route::put('/catalogs/{catalog}', [CatalogController::class, 'update'])->whereNumber('catalog')->middleware('agricultural.permission:empresa.editar');
    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::post('/catalogs', [CatalogController::class, 'store'])->middleware('agricultural.permission:empresa.editar');
    Route::patch('/catalogs/{catalog}', [CatalogController::class, 'toggle'])->whereNumber('catalog')->middleware('agricultural.permission:empresa.editar');
    Route::get('/sampling-procedures/{procedure}', [CatalogController::class, 'showProcedure'])->whereNumber('procedure');
    Route::get('/sampling-procedures', [CatalogController::class, 'procedures']);
    Route::post('/sampling-procedures', [CatalogController::class, 'procedure'])->middleware('agricultural.permission:empresa.editar');
    Route::patch('/sampling-procedures/{procedure}', [CatalogController::class, 'toggleProcedure'])->whereNumber('procedure')->middleware('agricultural.permission:empresa.editar');
    Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('agricultural.permission:reportes.operativos');
});
