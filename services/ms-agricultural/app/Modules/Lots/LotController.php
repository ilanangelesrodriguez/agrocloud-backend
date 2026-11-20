<?php

namespace App\Modules\Lots;

use App\Http\Controllers\Controller;
use App\Http\Requests\LotRequest;
use App\Http\Resources\AgriculturalResource;
use App\Models\Incidencia;
use App\Models\Lote;
use App\Models\Productor;
use App\Modules\Catalogs\CatalogService;
use App\Shared\Events\EventOutbox;
use App\Shared\Events\Traceability;
use App\Shared\Quota\UsageService;
use App\Shared\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['estado' => ['nullable', 'string', 'max:40']]);
        $query = Lote::with('productor')->when($data['estado'] ?? null, fn ($query, $status) => $query->where('estado', $status));
        if (($data['estado'] ?? null) === 'CLASIFICADO') {
            $blocked = Incidencia::whereIn('gravedad', ['ALTA', 'CRITICA'])->where('estado', '<>', 'CERRADA')->select('id_lote');
            $query->whereNotIn('id_lote', $blocked);
        }

        return AgriculturalResource::collection($query->orderByDesc('id_lote')->paginate(25));
    }

    public function released(Request $request): AnonymousResourceCollection
    {
        $request->merge(['estado' => 'CLASIFICADO']);

        return $this->index($request);
    }

    public function show(Request $request, int $lot): AgriculturalResource
    {
        $record = Lote::with(['productor', 'recepcion', 'muestreos.inspecciones.dictamenes'])->findOrFail($lot);
        $record->setAttribute('workflow', $record->workflowSummary($request->user()));

        return new AgriculturalResource($record);
    }

    public function store(LotRequest $request, CatalogService $catalog, UsageService $usage, LotService $lots): AgriculturalResource
    {
        $data = $catalog->validate($request->validated(), ['producto' => 'producto', 'variedad' => 'variedad', 'unidad_medida' => 'unidad']);
        Productor::findOrFail($data['id_productor']);
        $data['peso_kg'] = $catalog->weight((string) $data['peso_kg'], $data['unidad_medida']);
        $data['unidad_medida'] = 'Kilogramo';
        $data['fecha_llegada_prevista'] = CarbonImmutable::parse($data['fecha_llegada_prevista'])->utc()->toDateTimeString();
        $key = strtolower((string) $request->header('Idempotency-Key'));
        abort_unless(Str::isUuid($key), 422, 'A UUID Idempotency-Key is required.');
        $completed = DB::table('agricultural_operations')->where('id_empresa', app(CompanyContext::class)->company())->where('request_id', $key)->whereNotNull('result')->exists();
        abort_if(! $completed && Lote::where('codigo_lote', $data['codigo_lote'])->exists(), 409, 'Lot code already exists.');
        $result = $usage->run($key, 'lotes', $data, fn (): array => $lots->create($data));

        return new AgriculturalResource(Lote::findOrFail($result['id_lote']));
    }

    public function cancel(Request $request, int $lot): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($lot, $data): void {
            $record = Lote::lockForUpdate()->findOrFail($lot);
            abort_unless($record->estado === 'PENDIENTE_RECEPCION' && ! $record->recepcion()->exists() && ! $record->muestreos()->exists(), 422, 'Only an unreceived lot can be cancelled.');
            $record->update(['estado' => 'ANULADO']);
            Traceability::record($record, $lot, 'LOTE_ANULADO', $data);
            EventOutbox::add('lot.cancelled', ['lotId' => $lot]);
        });

        return response()->json(['cancelled' => true]);
    }

    public function traceability(int $lot): JsonResponse
    {
        Lote::findOrFail($lot);

        return response()->json(DB::table('eventos_trazabilidad')->where('id_empresa', app(CompanyContext::class)->company())->where('id_lote', $lot)->orderBy('id')->paginate(50));
    }
}
