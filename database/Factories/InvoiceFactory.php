<?php

namespace Database\Factories;

use App\Enums\InvoiceEstado;
use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_id' => InvoiceBatch::factory(),
            'billing_company_id' => BillingCompany::factory(),
            'importe' => fake()->randomFloat(2, 1000, 100000),
            'pto_vta' => 2,
            'tipo_comprobante' => 11,
            'codigo' => '0006',
            'fecha_comprobante' => now()->toDateString(),
            'fecha_servicio_desde' => now()->startOfMonth()->toDateString(),
            'fecha_servicio_hasta' => now()->endOfMonth()->toDateString(),
            'fecha_vto_pago' => now()->addDays(10)->toDateString(),
            'receptor_razon_social' => fake()->company().' Seguros',
            'receptor_cuit' => (string) fake()->numerify('30#########'),
            'receptor_condicion_iva' => 'RI',
            'estado' => InvoiceEstado::Pending,
        ];
    }

    public function authorized(): static
    {
        return $this->state(fn (): array => [
            'estado' => InvoiceEstado::Authorized,
            'numero_comprobante' => fake()->numberBetween(1, 9999),
            'cae' => (string) fake()->numerify('7###############'),
            'cae_vencimiento' => now()->addDays(10)->toDateString(),
            'pdf_path' => 'invoices/'.now()->format('Y-m').'/factura-c-2-00000001.pdf',
        ]);
    }
}
