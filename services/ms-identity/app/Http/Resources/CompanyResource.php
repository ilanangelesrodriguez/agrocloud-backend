<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id_empresa', 'ruc', 'razon_social', 'nombre_comercial', 'tipo_empresa', 'direccion', 'telefono', 'correo', 'estado', 'sunat_verificacion']);
    }
}
