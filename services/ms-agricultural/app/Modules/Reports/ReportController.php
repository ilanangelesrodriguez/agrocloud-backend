<?php

namespace App\Modules\Reports;

use App\Http\Controllers\Controller;
use App\Models\Incidencia;
use App\Models\Inspeccion;
use App\Models\Lote;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json(['lots_by_status' => Lote::selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->get(),
            'inspections_by_status' => Inspeccion::selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->get(),
            'open_critical_incidents' => Incidencia::whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->count()]);
    }
}
