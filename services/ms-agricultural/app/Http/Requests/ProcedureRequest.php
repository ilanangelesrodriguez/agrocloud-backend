<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['producto_id' => ['nullable', 'integer', 'min:1'], 'metodo_id' => ['nullable', 'integer', 'min:1'], 'nombre' => ['required', 'string', 'max:150'], 'version' => ['required', 'string', 'max:30'], 'metodo' => ['required', 'string', 'max:80'],
            'producto' => ['nullable', 'string', 'max:150'], 'criterio_muestra' => ['required', 'string', 'max:1500'], 'seleccion_frutos' => ['required', 'string', 'max:1500'],
            'tamano_recomendado' => ['nullable', 'integer', 'min:1', 'lte:max_frutos'], 'max_frutos' => ['required', 'integer', 'min:1', 'max:'.config('agricultural.sampling_max_fruits')],
            'requiere_poblacion' => ['sometimes', 'boolean']];
    }
}
