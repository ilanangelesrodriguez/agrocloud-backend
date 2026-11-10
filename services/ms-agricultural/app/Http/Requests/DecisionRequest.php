<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['dictamen_anterior' => ['nullable', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['APROBADO', 'OBSERVADO', 'RECHAZADO'])],
            'tipo_observacion' => ['nullable', Rule::in(['REINSPECCION', 'DOCUMENTACION', 'CORRECCION_ADMINISTRATIVA'])],
            'respuesta_observacion' => ['nullable', 'string', 'max:3000'], 'motivo' => ['nullable', 'string', 'max:200'], 'categoria' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['required', 'string', 'max:3000'], 'protocolo_calidad' => ['required', 'string', 'max:200'], 'revision_independiente' => ['required', 'accepted']];
    }
}
