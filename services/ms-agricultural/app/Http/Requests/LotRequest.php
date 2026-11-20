<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['producto_id' => ['nullable', 'integer', 'min:1'], 'variedad_id' => ['nullable', 'integer', 'min:1'], 'id_productor' => ['required', 'integer', 'min:1'], 'codigo_lote' => ['required', 'string', 'max:50'],
            'producto' => ['required', 'string', 'max:150'], 'variedad' => ['nullable', 'string', 'max:150'],
            'peso_kg' => ['required', 'numeric', 'min:0.00001', 'max:99999999999'], 'unidad_medida' => ['required', 'string', 'max:40'],
            'fecha_llegada_prevista' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/D']];
    }
}
