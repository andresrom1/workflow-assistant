<?php

namespace Database\Factories;

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
        ];
    }
}
