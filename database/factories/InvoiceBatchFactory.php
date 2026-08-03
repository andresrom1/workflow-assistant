<?php

namespace Database\Factories;

use App\Models\InvoiceBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceBatch>
 */
class InvoiceBatchFactory extends Factory
{
    protected $model = InvoiceBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => '0006',
            'concepto' => 'Comisiones correspondientes a Junio 2026',
            'punto_venta' => 2,
            'fecha_comprobante' => now()->toDateString(),
            'fecha_servicio_desde' => now()->startOfMonth()->toDateString(),
            'fecha_servicio_hasta' => now()->endOfMonth()->toDateString(),
            'fecha_vto_pago' => now()->addDays(10)->toDateString(),
            'estado' => 'processing',
        ];
    }

    public function completed(): static
    {
        return $this->state(['estado' => 'completed', 'finished_at' => now()]);
    }
}
