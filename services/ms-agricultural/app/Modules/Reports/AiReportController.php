<?php

namespace App\Modules\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalResource;
use App\Models\EvidenciaInspeccion;
use App\Models\Productor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiReportController extends Controller
{
    public function producers(): AnonymousResourceCollection
    {
        return AgriculturalResource::collection(Productor::select('id_productor', 'nombre_razon_social')->orderBy('nombre_razon_social')->paginate(50));
    }

    public function show(Request $request, string $section): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'model_id' => ['nullable', 'string', 'max:200'], 'producto' => ['nullable', 'string', 'max:150'], 'variedad' => ['nullable', 'string', 'max:150'], 'producer_id' => ['nullable', 'integer', 'min:1']]);
        $query = EvidenciaInspeccion::query()->where(fn ($q) => $q->whereNull('pertinencia')->orWhere('pertinencia', '<>', 'INVALIDA'))
            ->when($data['from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))
            ->when($data['model_id'] ?? null, fn ($q, $value) => $q->where('version_modelo', $value));
        if (isset($data['producto']) || isset($data['variedad']) || isset($data['producer_id'])) {
            $query->whereHas('inspeccion.muestreo.lote', function ($q) use ($data): void {
                foreach (['producto', 'variedad', 'producer_id'] as $key) {
                    if (isset($data[$key])) {
                        $q->where($key === 'producer_id' ? 'id_productor' : $key, $data[$key]);
                    }
                }
            });
        }
        $base = $query->select(['id_evidencia', 'id_empresa', 'created_at', 'version_modelo', 'estado_ia', 'resultado_ia'])->toBase();
        $cte = 'WITH selected AS ('.$base->toSql().'), detections AS (
            SELECT s.id_evidencia, s.id_empresa, d->>\'id\' AS detection_id, d->>\'class_name\' AS class_name,
                (d->>\'confidence\')::numeric AS confidence
            FROM selected s CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(s.resultado_ia::jsonb->\'detections\') = \'array\' THEN s.resultado_ia::jsonb->\'detections\' ELSE \'[]\'::jsonb END) d
            WHERE s.estado_ia = \'COMPLETADO\'
        ), reviewed AS (
            SELECT d.*, v.confirmada FROM detections d LEFT JOIN validacion_deteccion v
                ON v.id_empresa=d.id_empresa AND v.id_evidencia=d.id_evidencia AND v.detection_id=d.detection_id
        ) ';
        $sql = match ($section) {
            'overview' => "SELECT (SELECT COUNT(*) FROM selected) AS evidences,
                (SELECT COUNT(*) FROM selected WHERE estado_ia='COMPLETADO') AS analyzed,
                (SELECT COUNT(*) FROM selected WHERE estado_ia IN ('EN_COLA','PROCESANDO')) AS processing,
                (SELECT COUNT(*) FROM selected WHERE estado_ia='ERROR') AS failed,
                COUNT(*) AS detections, COUNT(*) FILTER (WHERE confirmada IS TRUE) AS confirmed,
                COUNT(*) FILTER (WHERE confirmada IS FALSE) AS discarded, COUNT(*) FILTER (WHERE confirmada IS NULL) AS pending FROM reviewed",
            'classes' => 'SELECT class_name, COUNT(*) AS detections, AVG(confidence) AS mean_confidence,
                COUNT(*) FILTER (WHERE confirmada IS TRUE) AS confirmed, COUNT(*) FILTER (WHERE confirmada IS FALSE) AS discarded,
                COUNT(*) FILTER (WHERE confirmada IS NULL) AS pending FROM reviewed GROUP BY class_name ORDER BY detections DESC',
            'daily' => "SELECT created_at::date AS day, COUNT(*) AS evidences, COUNT(*) FILTER (WHERE estado_ia='COMPLETADO') AS analyzed,
                COUNT(*) FILTER (WHERE estado_ia='ERROR') AS failed FROM selected GROUP BY created_at::date ORDER BY day",
            'models' => 'SELECT version_modelo AS model_id, COUNT(*) AS evidences FROM selected WHERE version_modelo IS NOT NULL GROUP BY version_modelo ORDER BY evidences DESC',
            'validation' => 'SELECT COUNT(*) FILTER (WHERE confirmada IS TRUE) AS confirmed, COUNT(*) FILTER (WHERE confirmada IS FALSE) AS discarded,
                COUNT(*) FILTER (WHERE confirmada IS NULL) AS pending FROM reviewed',
            default => abort(404),
        };
        $rows = DB::select($cte.$sql, $base->getBindings());

        return response()->json(['scope' => 'company', 'timezone' => 'UTC', 'data' => in_array($section, ['overview', 'validation'], true) ? ($rows[0] ?? null) : $rows,
            'note' => 'Validación humana de propuestas IA de la empresa; no equivale a precisión, recall ni mAP. No incluye fotografías descartadas.']);
    }
}
