<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Empresa;
use App\Services\Access\CompanyOnboarding;
use App\Services\SunatRucService;
use App\Support\UbigeoCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        return CompanyResource::collection(Empresa::query()->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereKey($actor->id_empresa))->orderBy('id_empresa')->paginate(25));
    }

    public function show(Request $request, int $company): CompanyResource
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->id_empresa === $company, 404);

        return new CompanyResource(Empresa::findOrFail($company));
    }

    public function store(CompanyRequest $request, SunatRucService $sunat): CompanyResource
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = app(UbigeoCatalog::class)->normalize($request->validated());
        $data['sunat_verificacion'] = $sunat->verificar($data['ruc'], $data['razon_social']);

        $administrator = $data['administrator'] ?? null;
        unset($data['administrator']);
        if ($administrator) {
            abort_if(($data['estado'] ?? true) === false, 422, 'An assisted onboarding requires an active company.');
            $provisioned = app(CompanyOnboarding::class)->provision($data, $administrator, $request->user());

            return new CompanyResource($provisioned['company']);
        }

        return new CompanyResource(Empresa::create($data));
    }

    public function update(CompanyRequest $request, int $company, SunatRucService $sunat): CompanyResource
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin() || $actor->id_empresa === $company, 404);
        $data = $request->validated();
        abort_if(! $actor->isSuperAdmin() && (array_key_exists('estado', $data) || array_key_exists('ruc', $data)), 403, 'Only platform administrators can change RUC or status.');
        $record = Empresa::findOrFail($company);
        $geoKeys = ['departamento', 'provincia', 'distrito', 'ubigeo'];
        if (count(array_intersect($geoKeys, array_keys($data))) > 0) {
            $existing = $record->only($geoKeys);
            $data = array_merge($data, app(UbigeoCatalog::class)->normalize(array_replace($existing, array_intersect_key($data, array_flip($geoKeys)))));
        }
        if (isset($data['ruc']) || isset($data['razon_social'])) {
            $data['sunat_verificacion'] = $sunat->verificar($data['ruc'] ?? $record->ruc, $data['razon_social'] ?? $record->razon_social);
        }
        $record = DB::transaction(function () use ($company, $data): Empresa {
            $record = Empresa::lockForUpdate()->findOrFail($company);
            $record->update($data);
            if (array_key_exists('estado', $data) && ! $data['estado']) {
                DB::table('identity_sessions')->whereIn('id_usuario', DB::table('usuario')->where('id_empresa', $company)->select('id_usuario'))->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            }

            return $record;
        }, 3);

        return new CompanyResource($record);
    }
}
