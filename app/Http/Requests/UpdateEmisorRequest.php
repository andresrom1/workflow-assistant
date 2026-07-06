<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'razon_social' => ['required', 'string', 'max:255'],
            'cuit' => ['required', 'string', 'regex:/^\d{11}$/'],
            'punto_venta' => ['required', 'integer', 'min:1', 'max:99999'],
            'condicion_iva' => ['required', 'string', 'max:100'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'ingresos_brutos' => ['nullable', 'string', 'max:100'],
            'inicio_actividades' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'cuit.regex' => 'El CUIT debe tener 11 dígitos, sin guiones ni puntos.',
        ];
    }
}
