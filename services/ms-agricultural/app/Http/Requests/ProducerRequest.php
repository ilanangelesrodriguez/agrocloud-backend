<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProducerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['ruc' => ['required', 'string', 'regex:/^[0-9]{11}$/D'], 'nombre_razon_social' => ['required', 'string', 'max:200'],
            'fundo' => ['nullable', 'string', 'max:200'], 'ubicacion' => ['nullable', 'string', 'max:250'],
            'departamento' => ['nullable', 'string', 'max:100'], 'provincia' => ['nullable', 'string', 'max:100'], 'distrito' => ['nullable', 'string', 'max:100'], 'ubigeo' => ['nullable', 'string', 'regex:/^\d{6}$/D']];
    }
}
