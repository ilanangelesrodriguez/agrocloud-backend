<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['id_empresa', 'id_rol'] as $field) {
            if (is_string($this->input($field)) && ctype_digit($this->input($field))) {
                $this->merge([$field => (int) $this->input($field)]);
            }
        }
        if (is_string($this->input('correo'))) {
            $this->merge(['correo' => mb_strtolower(trim($this->input('correo')))]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'id_empresa' => ['sometimes', 'integer', 'exists:empresa,id_empresa'],
            'id_rol' => [$required, 'integer', 'exists:rol,id_rol'],
            'nombre' => [$required, 'string', 'max:150'], 'apellido' => ['sometimes', 'nullable', 'string', 'max:150'],
            'correo' => [$required, 'email', 'max:255', Rule::unique('usuario', 'correo')->ignore($this->route('user'), 'id_usuario'), Rule::unique('usuario', 'pending_email')->ignore($this->route('user'), 'id_usuario')],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30'], 'cargo' => ['sometimes', 'nullable', 'string', 'max:120'],
            'estado' => ['sometimes', 'boolean'],
        ];
    }
}
