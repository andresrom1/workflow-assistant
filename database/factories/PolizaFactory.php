<?php

namespace Database\Factories;

use App\Enums\PolizaEstado;
use App\Models\Poliza;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poliza>
 */
class PolizaFactory extends Factory
{
    protected $model = Poliza::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'risk_id' => Risk::factory(),
            'estado' => PolizaEstado::Vigente,
            'numero' => 'POL-'.fake()->unique()->numberBetween(1000, 999999),
            'company' => fake()->randomElement(['La Caja Seguros', 'San Cristóbal', 'Galicia Seguros']),
            'coverage' => 'Todo riesgo C',
            'coverage_detail' => 'Daños totales + parciales + robo',
            'sum_asegurada' => fake()->numberBetween(5_000_000, 25_000_000),
            'cuota' => fake()->numberBetween(30_000, 150_000),
        ];
    }
}
