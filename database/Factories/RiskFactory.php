<?php

namespace Database\Factories;

use App\Enums\RiskType;
use App\Models\Customer;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Risk>
 */
class RiskFactory extends Factory
{
    protected $model = Risk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $marca = fake()->randomElement(['Toyota', 'Volkswagen', 'Ford', 'Renault', 'Peugeot']);
        $modelo = fake()->randomElement(['Corolla', 'Gol', 'Focus', 'Sandero', '208']);

        return [
            'customer_id' => Customer::factory(),
            'type' => RiskType::Vehicle,
            'label' => "{$marca} {$modelo}",
            'metadata' => [
                'patente' => strtoupper(fake()->bothify('?? ### ??')),
                'marca' => $marca,
                'modelo' => $modelo,
            ],
        ];
    }
}
