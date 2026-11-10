<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'ruc' => [$required, 'string', 'regex:/^[0-9]{11}$/D', Rule::unique('empresa', 'ruc')->ignore($this->route('company'), 'id_empresa')],
            'razon_social' => [$required, 'string', 'max:200'], 'nombre_comercial' => [$required, 'string', 'max:150'],
            'tipo_empresa' => ['sometimes', 'nullable', 'string', 'max:80'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:250'], 'telefono' => ['sometimes', 'nullable', 'string', 'max:30'],
            'correo' => ['sometimes', 'nullable', 'email', 'max:255'], 'estado' => ['sometimes', 'boolean'],
        ];
    }
}
