<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskSnapshotFactory extends Factory
{
    protected $model = RiskSnapshot::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'vehicle_id' => Vehicle::factory(),
            'marca' => 'Peugeot',
            'modelo' => '2008',
            'version' => '1.6 ALLURE',
            'year' => 2017,
            'combustible' => 'nafta',
            'uso' => 'particular',
            'codigo_postal' => '5000',
            'dni' => (string) $this->faker->numberBetween(20_000_000, 45_000_000),
            'edad_conductor' => null,
            'coverage_preference' => null,
        ];
    }
}
