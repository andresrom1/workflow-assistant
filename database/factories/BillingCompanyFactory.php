<?php

namespace Database\Factories;

use App\Models\BillingCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCompany>
 */
class BillingCompanyFactory extends Factory
{
    protected $model = BillingCompany::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'razon_social' => fake()->company().' Seguros',
            'cuit' => (string) fake()->unique()->numerify('30#########'),
            'condicion_iva' => 'RI',
            'activo' => true,
        ];
    }
}
