<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            // Generamos un DNI realista (string numérico)
            'dni' => (string) $this->faker->unique()->numberBetween(20000000, 95000000),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'is_anonymous' => false,
            'completed_at' => null,
            'metadata' => ['source' => 'factory_seeder'],
        ];
    }

    /**
     * Estado para cliente anónimo (sin DNI ni contacto).
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_anonymous' => true,
            'dni' => null,
            'email' => null,
            'phone' => null,
            'name' => null,
        ]);
    }
}