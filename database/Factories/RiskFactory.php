<?php

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Customer;
use App\Models\InsurableAsset;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Risk>
 */
class RiskFactory extends Factory
{
    protected $model = Risk::class;

    /**
     * Los atributos del bien (patente, marca, modelo) viven en el
     * InsurableAsset asociado, no en el Risk (ver docs/v3/05).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'asset_id' => fn (array $attributes) => InsurableAsset::factory()
                ->create(['customer_id' => $attributes['customer_id']])->id,
            'type' => AssetType::Vehicle,
            'label' => 'Vehículo',
            'metadata' => [],
        ];
    }
}
