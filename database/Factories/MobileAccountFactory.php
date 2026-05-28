<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\MobileAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileAccount>
 */
class MobileAccountFactory extends Factory
{
    protected $model = MobileAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'firebase_uid' => 'uid_'.fake()->unique()->bothify('??????????'),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => fake()->imageUrl(96, 96),
            'email_verified_at' => now(),
            'customer_id' => null,
        ];
    }

    /**
     * Una cuenta ya vinculada a un Customer (post-DNI).
     */
    public function linked(?int $customerId = null): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customerId ?? Customer::factory(),
        ]);
    }
}
