<?php

namespace App\Modules\Reports;

use App\Http\Controllers\Controller;
use App\Models\DictamenCalidad;
use App\Models\EvidenciaInspeccion;
use App\Models\HallazgoManual;
use App\Models\Incidencia;
use App\Models\Inspeccion;
use App\Models\Lote;
use App\Models\ValidacionDeteccion;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $request->user();
        $metrics = [];
        $tasks = [];
        if ($actor->hasPermission('recepciones.crear')) {
            $metrics[] = ['key' => 'reception', 'label' => 'Por recibir', 'value' => Lote::where('estado', 'PENDIENTE_RECEPCION')->count(), 'href' => '/lots'];
            foreach (Lote::where('estado', 'PENDIENTE_RECEPCION')->orderBy('fecha_llegada_prevista')->limit(5)->get() as $lot) {
                if ($actor->rol->codigo === 'RECEPCION') {
                    $tasks[] = ['label' => 'Recibir '.$lot->codigo_lote, 'href' => '/lots/'.$lot->getKey().'/reception'];
                }
            }
        }
        if ($actor->hasPermission('muestreos.crear')) {
            $metrics[] = ['key' => 'sampling', 'label' => 'Por muestrear', 'value' => Lote::where('estado', 'RECIBIDO')->whereDoesntHave('muestreos')->count(), 'href' => '/lots'];
            foreach (Lote::where('estado', 'RECIBIDO')->whereDoesntHave('muestreos')->orderBy('id_lote')->limit(5)->get() as $lot) {
                if ($actor->rol->codigo === 'INSPECTOR') {
                    $tasks[] = ['label' => 'Muestrear '.$lot->codigo_lote, 'href' => '/lots/'.$lot->getKey().'/sampling'];
                }
            }
        }
        if ($actor->hasPermission('inspecciones.capturar')) {
            $assigned = Inspeccion::where('estado', 'EN_CAPTURA')->where(fn ($query) => $query->where('id_inspector', $actor->id_usuario)->orWhereNull('id_inspector'));
            $metrics[] = ['key' => 'inspection', 'label' => 'Inspecciones disponibles', 'value' => (clone $assigned)->count(), 'href' => '/inspections'];
            $metrics[] = ['key' => 'ai_failed', 'label' => 'Análisis con error', 'value' => EvidenciaInspeccion::whereIn('id_inspeccion', (clone $assigned)->select('id_inspeccion'))->where('estado_ia', 'ERROR')->count(), 'href' => '/inspections'];
            $company = app(CompanyContext::class)->company();
            $pendingEvidence = DB::table('frutos_muestra as fruit')
                ->join('inspeccion as inspection', 'inspection.id_muestreo', '=', 'fruit.id_muestreo')
                ->where('fruit.id_empresa', $company)->where('inspection.id_empresa', $company)
                ->whereIn('inspection.id_inspeccion', (clone $assigned)->select('id_inspeccion'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evidencia_inspeccion as evidence')
                    ->whereColumn('evidence.id_empresa', 'fruit.id_empresa')->whereColumn('evidence.id_fruto', 'fruit.id')
                    ->whereColumn('evidence.id_inspeccion', 'inspection.id_inspeccion')
                    ->where(fn ($q) => $q->whereNull('evidence.pertinencia')->orWhere('evidence.pertinencia', '<>', 'INVALIDA')))->count();
            $metrics[] = ['key' => 'evidence', 'label' => 'Frutos sin fotografía', 'value' => $pendingEvidence, 'href' => '/inspections'];
            foreach ((clone $assigned)->orderBy('id_inspeccion')->limit(5)->get() as $inspection) {
                if ($actor->rol->codigo === 'INSPECTOR') {
                    $tasks[] = ['label' => 'Completar inspección #'.$inspection->getKey(), 'href' => '/inspections/'.$inspection->getKey()];
                }
            }
        }
        if ($actor->hasPermission('calidad.clasificar')) {
            foreach (['PENDING' => 'Dictámenes pendientes', 'OBSERVED' => 'Observaciones por resolver'] as $status => $label) {
                $metrics[] = ['key' => $status, 'label' => $label, 'value' => Inspeccion::decisionStatus($status)->count(), 'href' => '/quality?status='.$status];
            }
            foreach (Inspeccion::decisionStatus('PENDING')->orderBy('id_inspeccion')->limit(5)->get() as $inspection) {
                if ($actor->rol->codigo === 'SUPERVISOR' && $inspection->id_inspector !== $actor->id_usuario) {
                    $tasks[] = ['label' => 'Dictaminar inspección #'.$inspection->getKey(), 'href' => '/inspections/'.$inspection->getKey()];
                }
            }
        }
        if ($actor->hasPermission('incidencias.ver')) {
            $incidents = Incidencia::where('estado', '<>', 'CERRADA')->when(! $actor->hasPermission('incidencias.cerrar'), fn ($query) => $query->where('responsable', $actor->id_usuario));
            $metrics[] = ['key' => 'incidents', 'label' => 'Incidencias por atender', 'value' => (clone $incidents)->count(), 'href' => '/incidents'];
            foreach ((clone $incidents)->orderBy('id')->limit(5)->get() as $incident) {
                $tasks[] = ['label' => $incident->titulo, 'href' => '/lots/'.$incident->id_lote];
            }
        }
        if ($actor->hasPermission('produccion.ver')) {
            $blocked = Incidencia::whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->select('id_lote');
            $metrics[] = ['key' => 'released', 'label' => 'Lotes liberados', 'value' => Lote::where('estado', 'CLASIFICADO')->whereNotIn('id_lote', $blocked)->count(), 'href' => '/production'];
        }

        return response()->json(['metrics' => $metrics, 'tasks' => $tasks]);
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'producto' => ['nullable', 'string', 'max:150'], 'variedad' => ['nullable', 'string', 'max:150'], 'producer_id' => ['nullable', 'integer', 'min:1']]);
        $lots = Lote::query()->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        foreach (['producto', 'variedad', 'producer_id'] as $filter) {
            if (isset($data[$filter])) {
                $lots->where($filter === 'producer_id' ? 'id_productor' : $filter, $data[$filter]);
            }
        }
        $lotIds = (clone $lots)->select('id_lote');
        $inspections = Inspeccion::whereHas('muestreo', fn ($q) => $q->whereIn('id_lote', $lotIds));
        $inspectionIds = (clone $inspections)->select('id_inspeccion');
        $evidenceIds = EvidenciaInspeccion::whereIn('id_inspeccion', $inspectionIds)->select('id_evidencia');
        $latestIds = DictamenCalidad::whereIn('id_lote', $lotIds)->selectRaw('MAX(id_dictamen)')->groupBy('id_lote');
        $decisions = DictamenCalidad::whereIn('id_dictamen', $latestIds);
        $counts = (clone $decisions)->selectRaw('decision, COUNT(*) AS total')->groupBy('decision')->pluck('total', 'decision');
        $total = (int) $counts->sum();
        $company = app(CompanyContext::class)->company();
        $byProduct = Incidencia::query()->join('lote', 'lote.id_lote', '=', 'incidencias.id_lote')->where('lote.id_empresa', $company)
            ->whereIn('incidencias.id_lote', $lotIds)->selectRaw('lote.producto, COUNT(*) AS total')->groupBy('lote.producto')->get();
        $leadTime = (clone $decisions)->join('recepciones', 'recepciones.id_lote', '=', 'dictamen_calidad.id_lote')->where('recepciones.id_empresa', $company)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (dictamen_calidad.created_at - recepciones.recibido_en)) / 3600) AS hours')->value('hours');
        $inspectionTime = (clone $inspections)->whereNotNull('validada_en')->selectRaw('AVG(EXTRACT(EPOCH FROM (validada_en - created_at)) / 3600) AS hours')->value('hours');
        $producerQuality = (clone $decisions)->join('lote', 'lote.id_lote', '=', 'dictamen_calidad.id_lote')->join('productor', 'productor.id_productor', '=', 'lote.id_productor')
            ->where('lote.id_empresa', $company)->where('productor.id_empresa', $company)
            ->selectRaw('productor.id_productor, productor.nombre_razon_social AS productor, dictamen_calidad.decision, COUNT(*) AS total')
            ->groupBy('productor.id_productor', 'productor.nombre_razon_social', 'dictamen_calidad.decision')->get();
        $validation = ValidacionDeteccion::whereIn('id_evidencia', $evidenceIds);

        return response()->json([
            'lots_by_status' => (clone $lots)->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->get(),
            'inspections_by_status' => (clone $inspections)->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->get(),
            'open_critical_incidents' => Incidencia::whereIn('id_lote', $lotIds)->whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->count(),
            'quality' => ['decided_lots' => $total, 'approval_rate' => $total ? round(100 * (int) ($counts['APROBADO'] ?? 0) / $total, 2) : 0,
                'rejection_rate' => $total ? round(100 * (int) ($counts['RECHAZADO'] ?? 0) / $total, 2) : 0,
                'observed_rate' => $total ? round(100 * (int) ($counts['OBSERVADO'] ?? 0) / $total, 2) : 0,
                'reception_to_decision_hours' => $leadTime === null ? null : round((float) $leadTime, 2),
                'inspection_hours' => $inspectionTime === null ? null : round((float) $inspectionTime, 2)],
            'frequent_defects' => HallazgoManual::whereIn('id_inspeccion', $inspectionIds)->selectRaw('clase, COUNT(*) AS total')->groupBy('clase')->orderByDesc('total')->limit(10)->get(),
            'incidents_by_product' => $byProduct, 'quality_by_producer' => $producerQuality,
            'ai_review' => ['confirmed' => (clone $validation)->where('confirmada', true)->count(), 'discarded' => (clone $validation)->where('confirmada', false)->count(),
                'requested_evidences' => DB::table('agricultural_ai_jobs')->where('id_empresa', $company)->whereIn('id_evidencia', $evidenceIds)->count()],
        ]);
    }
}
