<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SamplingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['procedure_id' => ['nullable', 'integer', 'min:1'], 'tamano_muestra' => ['required', 'integer', 'min:1', 'max:'.config('agricultural.sampling_max_fruits')],
            'metodo' => ['nullable', 'string', 'max:80'], 'protocolo' => ['nullable', 'string', 'max:200'],
            'criterio_muestra' => ['nullable', 'string', 'max:1500'], 'seleccion_frutos' => ['nullable', 'string', 'max:1500'],
            'poblacion_estimada' => ['nullable', 'integer', 'min:1', 'max:100000000'], 'fuente_poblacion' => ['nullable', 'string', 'max:500'],
            'motivo_excepcion' => ['nullable', 'string', 'max:1500'], 'motivo_desviacion' => ['nullable', 'string', 'max:1500'],
            'confirmar_instrucciones' => ['required', 'accepted'], 'confirmar_carga' => ['sometimes', 'accepted'], 'observaciones' => ['nullable', 'string', 'max:3000']];
    }
}
