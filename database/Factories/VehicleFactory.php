<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            // Si no se pasa un cliente, crea uno automáticamente
            'customer_id' => Customer::factory(),
            
            // Patente formato Mercosur (AA123BB) o Vieja (ABC123)
            'patente' => $this->faker->regexify('[A-Z]{2}[0-9]{3}[A-Z]{2}'),
            
            'marca' => $this->faker->randomElement(['Fiat', 'Ford', 'Toyota', 'Volkswagen', 'Chevrolet']),
            'modelo' => $this->faker->randomElement(['Cronos', 'Focus', 'Corolla', 'Gol Trend', 'Cruze']),
            'version' => $this->faker->randomElement(['1.6 MSI', '2.0 SE', 'XEI CVT', 'Highline']),
            'year' => $this->faker->numberBetween(2015, 2025),
            
            'combustible' => $this->faker->randomElement(['nafta', 'gnc', 'diesel', 'hibrido']),
            'codigo_postal' => (string) $this->faker->numberBetween(1000, 9400),
            'uso' => $this->faker->randomElement(['particular', 'comercial']),
            
            'is_complete' => true,
            'metadata' => [],
        ];
    }
}