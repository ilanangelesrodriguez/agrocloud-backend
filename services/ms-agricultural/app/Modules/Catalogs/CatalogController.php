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
        $item = DB::table('maestro_valores')->where('id', $catalog)->where(fn ($query) => $query->whereNull('id_empresa')->orWhere('id_empresa', $company))->first();
        abort_unless($item, 404);
        DB::table('maestro_empresa_config')->updateOrInsert(['id_empresa' => $company, 'maestro_id' => $catalog], ['estado' => $data['estado']]);

        return response()->json(['updated' => true]);
    }

    public function procedures(): AnonymousResourceCollection
    {
        return AgriculturalResource::collection(SamplingProcedure::orderByDesc('id')->paginate(25));
    }

    public function procedure(ProcedureRequest $request, CatalogService $catalog): AgriculturalResource
    {
        $request->user()->requireRole('ADMIN');
        $data = $catalog->validate($request->validated(), ['metodo' => 'metodo', 'producto' => 'producto']);
        abort_if(SamplingProcedure::where('nombre', $data['nombre'])->where('version', $data['version'])->exists(), 409, 'Procedure version already exists.');
        $record = DB::transaction(function () use ($data): SamplingProcedure {
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
            $record->update($data);
            Traceability::record($record, null, 'PROCEDIMIENTO_ESTADO', $data);

            return $record;
        });

        return new AgriculturalResource($record);
    }
}
