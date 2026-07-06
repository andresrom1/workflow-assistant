<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingCompanyRequest extends FormRequest
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
            'cuit' => [
                'required', 'string', 'regex:/^\d{11}$/',
                Rule::unique('billing_companies', 'cuit')->ignore($this->route('company')),
            ],
            'condicion_iva' => ['required', Rule::in(['RI', 'MT', 'EX', 'CF'])],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'cuit.regex' => 'El CUIT debe tener 11 dígitos, sin guiones ni puntos.',
            'cuit.unique' => 'Ya existe una compañía con ese CUIT.',
        ];
    }
}
