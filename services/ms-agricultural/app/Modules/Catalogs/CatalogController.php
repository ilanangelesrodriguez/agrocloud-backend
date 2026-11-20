<?php

namespace App\Modules\Catalogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcedureRequest;
use App\Http\Resources\AgriculturalResource;
use App\Models\SamplingProcedure;
use App\Shared\Events\Traceability;
use App\Shared\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request, CatalogService $catalog): JsonResponse
    {
        $data = $request->validate(['tipo' => ['required', Rule::in(['producto', 'variedad', 'unidad', 'metodo', 'defecto', 'categoria', 'motivo'])]]);

        return response()->json(['data' => $catalog->options($data['tipo'])]);
    }

    public function admin(Request $request): JsonResponse
    {
        $request->user()->requireRole('ADMIN');
        $data = $request->validate(['tipo' => ['required', Rule::in(['producto', 'variedad', 'unidad', 'metodo', 'defecto', 'categoria', 'motivo'])]]);
        $company = app(CompanyContext::class)->company();
        $items = DB::table('maestro_valores as value')
            ->leftJoin('maestro_empresa_config as config', fn ($join) => $join->on('config.maestro_id', '=', 'value.id')->where('config.id_empresa', $company))
            ->where('value.tipo', $data['tipo'])
            ->where(fn ($query) => $query->whereNull('value.id_empresa')->orWhere('value.id_empresa', $company))
            ->select('value.*')->selectRaw('value.estado AND COALESCE(config.estado, true) AS habilitado')->orderBy('value.nombre')->get();

        return response()->json(['data' => $items]);
    }

    public function update(Request $request, int $catalog): JsonResponse
    {
        $request->user()->requireRole('ADMIN');
        $data = $request->validate(['nombre' => ['required', 'string', 'max:150']]);
        $company = app(CompanyContext::class)->company();
        DB::transaction(function () use ($catalog, $company, $data): void {
            $item = DB::table('maestro_valores')->where('id', $catalog)->where('id_empresa', $company)->lockForUpdate()->first();
            abort_unless($item, 404);
            abort_if($item->tipo === 'unidad', 422);
            $name = trim($data['nombre']);
            abort_if($name === '', 422);
            if ($name !== $item->nombre) {
                $references = match ($item->tipo) {
                    'producto' => ['lote' => 'producto', 'sampling_procedures' => 'producto'],
                    'variedad' => ['lote' => 'variedad'],
                    'metodo' => ['sampling_procedures' => 'metodo', 'muestreo' => 'metodo'],
                    'defecto' => ['hallazgo_manual' => 'clase'],
                    'categoria' => ['dictamen_calidad' => 'categoria'],
                    'motivo' => ['dictamen_calidad' => 'motivo'], default => [],
                };
                foreach ($references as $table => $column) {
                    abort_if(DB::table($table)->where('id_empresa', $company)->when(in_array($column, ['producto', 'variedad', 'metodo'], true), fn ($q) => $q->whereNull($column.'_id'))->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower(trim($item->nombre))])->exists(), 409,
                        'No se puede renombrar un maestro en uso. Deshabilítalo y registra un valor nuevo para conservar el historial.');
                }
            }
            $key = hash('sha256', $company.'|'.$item->tipo.'|'.($item->parent_id ?? '').'|'.mb_strtolower($name));
            abort_if(DB::table('maestro_valores')->where('clave', $key)->where('id', '<>', $catalog)->exists(), 409, 'Catalog value already exists.');
            DB::table('maestro_valores')->where('id', $catalog)->where('id_empresa', $company)->update(['nombre' => $name, 'clave' => $key, 'updated_at' => now()]);
        });

        return response()->json(['updated' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->requireRole('ADMIN');
        $data = $request->validate(['tipo' => ['required', Rule::in(['producto', 'variedad', 'metodo', 'defecto', 'categoria', 'motivo'])],
            'nombre' => ['required', 'string', 'max:150'], 'parent_id' => ['nullable', 'integer', 'min:1']]);
        $parent = $data['parent_id'] ?? null;
        if ($data['tipo'] === 'variedad') {
            abort_unless($parent && app(CatalogService::class)->options('producto')->contains('id', (int) $parent), 422, 'An active visible product parent is required.');
        } else {
            abort_if($parent !== null, 422, 'Only varieties have a parent product.');
        }
        $company = app(CompanyContext::class)->company();
        $key = hash('sha256', $company.'|'.$data['tipo'].'|'.($parent ?? '').'|'.mb_strtolower(trim($data['nombre'])));
        DB::table('maestro_valores')->insertOrIgnore([...$data, 'id_empresa' => $company, 'clave' => $key, 'estado' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('maestro_valores')->where('id_empresa', $company)->where('clave', $key)->first()], 201);
    }

    public function toggle(Request $request, int $catalog): JsonResponse
    {
        $request->user()->requireRole('ADMIN');
        $data = $request->validate(['estado' => ['required', 'boolean']]);
        $company = app(CompanyContext::class)->company();
        DB::transaction(function () use ($catalog, $company, $data): void {
            $item = DB::table('maestro_valores')->where('id', $catalog)->where(fn ($query) => $query->whereNull('id_empresa')->orWhere('id_empresa', $company))->lockForUpdate()->first();
            abort_unless($item, 404);
            if (! $data['estado'] && in_array($item->tipo, ['metodo', 'producto'], true)) {
                abort_if(SamplingProcedure::where('activo', true)->where(fn ($q) => $q->where($item->tipo.'_id', $item->id)->orWhere(fn ($q) => $q->whereNull($item->tipo.'_id')->where($item->tipo, $item->nombre)))->exists(), 409,
                    'Reemplaza o desactiva primero los procedimientos activos que dependen de este maestro.');
            }
            DB::table('maestro_empresa_config')->updateOrInsert(['id_empresa' => $company, 'maestro_id' => $catalog], ['estado' => $data['estado']]);
        }, 3);

        return response()->json(['updated' => true]);
    }

    public function procedures(Request $request, CatalogService $catalog): AnonymousResourceCollection
    {
        $data = $request->validate(['producto' => ['nullable', 'string', 'max:150'], 'producto_id' => ['nullable', 'integer', 'min:1'], 'activo' => ['nullable', Rule::in(['true', 'false', '1', '0'])]]);
        $query = ($request->boolean('activo') && (isset($data['producto_id']) || isset($data['producto'])))
            ? $catalog->applicableProcedures(isset($data['producto_id']) ? (int) $data['producto_id'] : null, $data['producto'] ?? null)
            : SamplingProcedure::query()->when(isset($data['activo']), fn ($q) => $q->where('activo', $request->boolean('activo')))
                ->when($data['producto_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->whereNull('producto_id')->orWhere('producto_id', $id)))
                ->when($data['producto'] ?? null, fn ($q, $name) => $q->where(fn ($q) => $q->whereNull('producto')->orWhere('producto', $name)));

        return AgriculturalResource::collection($query->orderByDesc('id')->paginate(25));
    }

    public function showProcedure(int $procedure): AgriculturalResource
    {
        return new AgriculturalResource(SamplingProcedure::findOrFail($procedure));
    }

    public function procedure(ProcedureRequest $request, CatalogService $catalog): AgriculturalResource
    {
        $request->user()->requireRole('ADMIN');
        $record = DB::transaction(function () use ($request, $catalog): SamplingProcedure {
            $data = $catalog->validate($request->validated(), ['metodo' => 'metodo', 'producto' => 'producto']);
            abort_if(SamplingProcedure::where('nombre', $data['nombre'])->where('version', $data['version'])->exists(), 409, 'Procedure version already exists.');
            $record = SamplingProcedure::create($data);
            Traceability::record($record, null, 'PROCEDIMIENTO_CREADO', $data);

            return $record;
        });

        return new AgriculturalResource($record);
    }

    public function toggleProcedure(Request $request, int $procedure): AgriculturalResource
    {
        $request->user()->requireRole('ADMIN');
        $data = $request->validate(['activo' => ['required', 'boolean']]);
        $record = DB::transaction(function () use ($procedure, $data): SamplingProcedure {
            $record = SamplingProcedure::lockForUpdate()->findOrFail($procedure);
            if ($data['activo']) {
                app(CatalogService::class)->validate($record->only(['producto', 'producto_id', 'metodo', 'metodo_id']), ['producto' => 'producto', 'metodo' => 'metodo']);
            }
            $record->update($data);
            Traceability::record($record, null, 'PROCEDIMIENTO_ESTADO', $data);

            return $record;
        });

        return new AgriculturalResource($record);
    }
}
