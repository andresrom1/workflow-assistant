<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceBatchRequest extends FormRequest
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
            'codigo' => ['required', 'string', 'max:50'],
            'concepto' => ['required', 'string', 'max:255'],
            'fecha_servicio_desde' => ['required', 'date'],
            'fecha_servicio_hasta' => ['required', 'date', 'after_or_equal:fecha_servicio_desde'],
            'fecha_vto_pago' => ['required', 'date'],
            'empresas' => ['required', 'array', 'min:1'],
            'empresas.*.id' => ['required', 'integer', 'exists:billing_companies,id'],
            'empresas.*.importe' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
