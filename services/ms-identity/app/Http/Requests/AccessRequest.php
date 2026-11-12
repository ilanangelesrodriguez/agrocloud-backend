<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo'))) {
            $this->merge(['correo' => mb_strtolower(trim($this->input('correo')))]);
        }
    }

    public function rules(): array
    {
        $privacyVersion = (string) config('identity.privacy_version');
        abort_if(trim($privacyVersion) === '', 503, 'La versión del aviso de privacidad no está configurada.');

        return [
            'privacy_accepted' => ['required', 'accepted'],
            'privacy_version' => ['required', 'string', 'max:80', Rule::in([$privacyVersion])],
            'ruc' => ['required', 'string', 'regex:/^[0-9]{11}$/D', Rule::unique('solicitudes_acceso', 'ruc')->whereNot('estado', 'RECHAZADA'), 'unique:empresa,ruc'],
            'razon_social' => ['required', 'string', 'max:200'], 'nombre_comercial' => ['required', 'string', 'max:150'],
            'tipo_empresa' => ['required', 'string', 'max:80'], 'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'], 'correo' => ['required', 'email', 'max:255', 'unique:usuario,correo', 'unique:usuario,pending_email'],
            'telefono' => ['required', 'string', 'max:30'], 'uso' => ['required', 'string', 'max:4000'],
        ];
    }
}
