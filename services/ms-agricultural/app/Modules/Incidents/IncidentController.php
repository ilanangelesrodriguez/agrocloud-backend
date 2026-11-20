<?php

namespace App\Modules\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalResource;
use App\Integrations\Identity\IdentityClient;
use App\Models\EvidenciaInspeccion;
use App\Models\Incidencia;
use App\Models\Lote;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(?int $lot = null): AnonymousResourceCollection
    {
        if ($lot !== null) {
            Lote::findOrFail($lot);
        }

        return AgriculturalResource::collection(Incidencia::when($lot !== null, fn ($query) => $query->where('id_lote', $lot))->orderByDesc('id')->paginate(25));
    }

    public function responsibles(Request $request, IdentityClient $identity): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $company = app(CompanyContext::class)->company();
        $result = $identity->call('GET', '/companies/'.$company.'/recipients', ['permission' => 'incidencias.ver', 'page' => $data['page'] ?? 1]);
        $items = collect($result['data'] ?? [])->map(fn ($user) => ['id' => (int) $user['id_usuario'], 'name' => trim(($user['nombre'] ?? '').' '.($user['apellido'] ?? ''))])->values();

        return response()->json(['data' => $items, 'meta' => ['current_page' => (int) $result['current_page'], 'last_page' => (int) $result['last_page']]]);
    }

    public function evidences(int $lot): AnonymousResourceCollection
    {
        Lote::findOrFail($lot);

        return AgriculturalResource::collection(EvidenciaInspeccion::select('id_evidencia', 'id_inspeccion', 'id_fruto')->with('fruto:id,numero,codigo')->whereHas('inspeccion.muestreo', fn ($query) => $query->where('id_lote', $lot))->orderByDesc('id_evidencia')->paginate(25));
    }

    public function history(int $incident): JsonResponse
    {
        Incidencia::findOrFail($incident);

        return response()->json(DB::table('eventos_trazabilidad')->where('id_empresa', app(CompanyContext::class)->company())
            ->where('entidad', 'Incidencia')->where('registro', $incident)->orderByDesc('id')->paginate(25));
    }

    public function store(Request $request, int $lot, IdentityClient $identity): AgriculturalResource
    {
        abort_unless(in_array($request->user()->rol->codigo, ['ADMIN', 'RECEPCION', 'INSPECTOR', 'SUPERVISOR'], true), 403);
        $record = Lote::findOrFail($lot);
        $data = $request->validate(['titulo' => ['required', 'string', 'max:150'], 'descripcion' => ['required', 'string', 'max:3000'],
            'gravedad' => ['required', Rule::in(['BAJA', 'MEDIA', 'ALTA', 'CRITICA'])], 'responsable' => ['required', 'integer', 'min:1']]);
        $identity->recipient($record->id_empresa, (int) $data['responsable']);
        $incident = DB::transaction(function () use ($lot, $data): Incidencia {
            $record = Lote::lockForUpdate()->findOrFail($lot);
            abort_if($record->estado === 'ANULADO', 422);
            $incident = Incidencia::create([...$data, 'id_lote' => $lot, 'estado' => 'ABIERTA']);
            if (in_array($data['gravedad'], ['ALTA', 'CRITICA'], true) && $record->estado === 'CLASIFICADO') {
                $record->update(['estado' => 'RETENIDO']);
            }
            Traceability::record($incident, $lot, 'INCIDENCIA_ABIERTA', $data);
            EventOutbox::add('incident.opened', ['lotId' => $lot, 'incidentId' => $incident->getKey(), 'severity' => $data['gravedad'], 'recipientIds' => [(int) $data['responsable']], 'recipientPermissions' => ['incidencias.cerrar']]);

            return $incident;
        }, 3);

        return new AgriculturalResource($incident);
    }

    public function update(Request $request, int $incident): AgriculturalResource
    {
        $data = $request->validate(['estado' => ['required', Rule::in(['ABIERTA', 'EN_ATENCION', 'RESUELTA', 'CERRADA'])], 'accion' => ['required', 'string', 'max:3000'],
            'evidencia' => ['nullable', 'integer', 'min:1'], 'comprobacion' => [Rule::requiredIf(in_array($request->input('estado'), ['RESUELTA', 'CERRADA'], true)), 'nullable', 'string', 'max:1500']]);
        $initial = Incidencia::findOrFail($incident);
        $result = DB::transaction(function () use ($request, $incident, $initial, $data): Incidencia {
            $lot = Lote::lockForUpdate()->findOrFail($initial->id_lote);
            $record = Incidencia::lockForUpdate()->findOrFail($incident);
            $supervisor = $request->user()->rol->codigo === 'SUPERVISOR' && $request->user()->hasPermission('incidencias.cerrar');
            abort_unless($supervisor || (int) $record->responsable === $request->user()->id_usuario, 403);
            $allowed = match ($record->estado) {
                'ABIERTA' => ['EN_ATENCION'], 'EN_ATENCION' => ['RESUELTA'],
                'RESUELTA' => $supervisor ? ['CERRADA', 'EN_ATENCION'] : [], 'CERRADA' => $supervisor ? ['ABIERTA'] : [], default => []
            };
            abort_unless(in_array($data['estado'], $allowed, true), 422, 'Invalid incident transition.');
            if (isset($data['evidencia'])) {
                abort_unless(EvidenciaInspeccion::whereKey($data['evidencia'])->whereHas('inspeccion.muestreo', fn ($query) => $query->where('id_lote', $lot->getKey()))->exists(), 422, 'Evidence must belong to this lot.');
            }
            $record->update(['estado' => $data['estado']]);
            $blocked = Incidencia::where('id_lote', $lot->getKey())->whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->exists();
            if ($blocked && $lot->estado === 'CLASIFICADO') {
                $lot->update(['estado' => 'RETENIDO']);
            }
            if (! $blocked && $lot->estado === 'RETENIDO') {
                $lot->update(['estado' => 'CLASIFICADO']);
            }
            Traceability::record($record, $lot->getKey(), 'INCIDENCIA_SEGUIMIENTO', $data);
            EventOutbox::add('incident.updated', ['lotId' => $lot->getKey(), 'incidentId' => $record->getKey(), 'status' => $data['estado'], 'recipientPermissions' => ['incidencias.cerrar']]);

            return $record;
        }, 3);

        return new AgriculturalResource($result);
    }
}
