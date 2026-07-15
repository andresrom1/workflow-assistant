<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_uuid' => fake()->uuid(),
            'risk_snapshot_id' => RiskSnapshot::factory(),
            'conversation_id' => Conversation::factory(),
            'status' => fake()->randomElement(['pending', 'processed', 'failed', 'expired', 'checkout_pending', 'checkout_submitted']),
            'external_ref_id' => fake()->optional()->uuid(),
            'resolution_method' => 'api',
            'metadata' => [],
            'expires_at' => now()->addDays(7),
            'checkout_token' => null,
            'checkout_alternative_id' => null,
        ];
    }
}
