<?php

namespace Database\Factories;

use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentExecutionLog>
 */
class AgentExecutionLogFactory extends Factory
{
    protected $model = AgentExecutionLog::class;

    private const AGENTS = [
        'CustomerIdentifierAgent',
        'VehicleIdentifierAgent',
        'CoveragePreferenceAgent',
        'QuoteAgent',
        'CheckoutAgent',
    ];

    private const STATE_DEFAULTS = [
        'customer_identified' => false,
        'vehicle_identified'  => false,
        'coverage_set'        => false,
        'quote_ready'         => false,
        'checkout_done'       => false,
    ];

    public function definition(): array
    {
        $step       = $this->faker->numberBetween(1, 5);
        $agentIndex = $step - 1;
        $agentName  = self::AGENTS[$agentIndex];

        $stateBefore = self::STATE_DEFAULTS;
        for ($i = 0; $i < $step - 1; $i++) {
            $stateBefore[array_keys(self::STATE_DEFAULTS)[$i]] = true;
        }

        $stateAfter   = $stateBefore;
        $changedKey   = array_keys(self::STATE_DEFAULTS)[$agentIndex] ?? null;
        $stateChanges = [];

        if ($changedKey) {
            $stateAfter[$changedKey]   = true;
            $stateChanges[$changedKey] = true;
        }

        return [
            'conversation_id'     => Conversation::factory(),
            'agent_name'          => $agentName,
            'step'                => $step,
            'state_before'        => $stateBefore,
            'state_after'         => $stateAfter,
            'state_changes'       => $stateChanges,
            'chained'             => false,
            'status'              => 'success',
            'error_message'       => null,
            'duration_ms'         => $this->faker->numberBetween(500, 5000),
            'inbound_message_ids' => null,
            'outbound_message_id' => null,
            'input_tokens'        => null,
            'output_tokens'       => null,
        ];
    }

    public function forStep(int $step): static
    {
        $agentIndex   = $step - 1;
        $agentName    = self::AGENTS[$agentIndex];
        $stateBefore  = self::STATE_DEFAULTS;

        for ($i = 0; $i < $step - 1; $i++) {
            $stateBefore[array_keys(self::STATE_DEFAULTS)[$i]] = true;
        }

        $stateAfter               = $stateBefore;
        $changedKey               = array_keys(self::STATE_DEFAULTS)[$agentIndex] ?? null;
        $stateChanges             = [];

        if ($changedKey) {
            $stateAfter[$changedKey]   = true;
            $stateChanges[$changedKey] = true;
        }

        return $this->state([
            'agent_name'    => $agentName,
            'step'          => $step,
            'state_before'  => $stateBefore,
            'state_after'   => $stateAfter,
            'state_changes' => $stateChanges,
        ]);
    }

    public function error(): static
    {
        return $this->state([
            'status'        => 'error',
            'state_changes' => [],
            'error_message' => $this->faker->sentence(),
        ]);
    }

    public function chained(): static
    {
        return $this->state(['chained' => true]);
    }
}
