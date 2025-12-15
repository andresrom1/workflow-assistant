<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            // Si no se pasa cliente, crea uno
            'customer_id' => Customer::factory(),
            
            // Simula IDs de OpenAI
            'external_conversation_id' => 'thread_' . $this->faker->uuid(),
            'external_user_id' => 'user_' . $this->faker->regexify('[a-zA-Z0-9]{10}'),
            
            'status' => 'active',
            'metadata' => ['provider' => 'openai'],
            'created_at' => now(),
        ];
    }
}