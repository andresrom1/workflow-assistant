<?php

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Customer;
use App\Models\InsurableAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsurableAsset>
 */
class InsurableAssetFactory extends Factory
{
    protected $model = InsurableAsset::class;

    public function definition(): array
    {
        $marca = fake()->randomElement(['Toyota', 'Volkswagen', 'Ford', 'Renault', 'Peugeot']);
        $modelo = fake()->randomElement(['Corolla', 'Gol', 'Focus', 'Sandero', '208']);

        return [
            'customer_id' => Customer::factory(),
            'type' => AssetType::Vehicle,
            'label' => "{$marca} {$modelo}",
            'metadata' => [
                'patente' => strtoupper(fake()->bothify('??###??')),
                'marca' => $marca,
                'modelo' => $modelo,
            ],
        ];
    }
}
