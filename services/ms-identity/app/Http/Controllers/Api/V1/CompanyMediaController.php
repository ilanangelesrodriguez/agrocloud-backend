<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Empresa;
use App\Services\Profile\AvatarStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompanyMediaController extends Controller
{
    private function authorizeCompany(Request $request, int $company): void
    {
        $user = $request->user();
        abort_unless(($user->isSuperAdmin() || $user->id_empresa === $company) && $user->hasPermission('empresa.editar'), 403);
    }

    public function upload(Request $request, int $company, AvatarStorage $storage): CompanyResource
    {
        $this->authorizeCompany($request, $company);
        $file = $request->validate(['logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096']])['logo'];
        Empresa::findOrFail($company);
        $uploaded = $storage->upload($file, 'agrocloud/empresas');
        try {
            $old = DB::transaction(function () use ($company, $uploaded): ?string {
                $record = Empresa::lockForUpdate()->findOrFail($company);
                $previous = $record->logo_public_id;
                $record->update(['logo_url' => $uploaded['url'], 'logo_public_id' => $uploaded['public_id']]);
                return $previous;
            }, 3);
        } catch (\Throwable $error) {
            $storage->deleteQuietly($uploaded['public_id'], 'agrocloud/empresas');
            throw $error;
        }
        $storage->deleteQuietly($old, 'agrocloud/empresas');
        return new CompanyResource(Empresa::findOrFail($company));
    }

    public function delete(Request $request, int $company, AvatarStorage $storage): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        $old = DB::transaction(function () use ($company): ?string {
            $record = Empresa::lockForUpdate()->findOrFail($company);
            $previous = $record->logo_public_id;
            $record->update(['logo_url' => null, 'logo_public_id' => null]);
            return $previous;
        }, 3);
        $storage->deleteQuietly($old, 'agrocloud/empresas');
        return response()->json(['deleted' => true]);
    }
}
