<?php

namespace App\Http\Resources;

use App\Models\Permiso;
use App\Services\Access\CompanyOnboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_usuario' => $this->id_usuario, 'id_empresa' => $this->id_empresa,
            'nombre' => $this->nombre, 'apellido' => $this->apellido, 'correo' => $this->correo,
            'telefono' => $this->telefono, 'cargo' => $this->cargo, 'estado' => $this->estado,
            'foto_perfil_url' => $this->foto_perfil_url,
            'pending_activation' => (bool) $this->invitation_hash,
            ...($request->user()?->getKey() === $this->id_usuario ? ['onboarding' => app(CompanyOnboarding::class)->nextStep($this->resource)] : []),
            'rol' => $this->rol?->only(['id_rol', 'codigo', 'nombre']),
            'permissions' => $this->isSuperAdmin() ? Permiso::where('estado', true)->pluck('codigo')
                : ($this->rol?->estado ? $this->rol->permisos()->where('permiso.estado', true)->pluck('permiso.codigo') : []),
        ];
    }
}
