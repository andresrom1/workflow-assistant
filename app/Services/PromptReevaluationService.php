<?php

namespace App\Services;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Contracts\Mockable;
use App\AI\Contracts\ReplayPolicy;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

/**
 * Reevalúa un turn histórico con un prompt editado (draft) sin persistir nada.
 *
 * Contratos:
 * - Nunca escribe en DB: cada tool MOCK devuelve respuesta canned via `interceptIfReplay()`.
 * - Nunca despacha jobs ni dispara WhatsApp: trabaja sólo con `AnonymousAgent`.
 * - El flag `ai.replay_mode` se limpia en `finally` aunque el LLM lance excepción.
 */
class PromptReevaluationService
{
    /**
     * @return array{
     *     response: string,
     *     agent_key: string,
     *     tool_calls: array<int, array{name: string, arguments: mixed, policy: string}>,
     *     usage: array{prompt_tokens: int, completion_tokens: int}
     * }
     */
    public function reevaluate(
        string $agentKey,
        int $conversationId,
        int $agentExecutionLogId,
        string $draftInstructions,
        ?string $overridePrompt = null,
    ): array {
        $conversation = Conversation::findOrFail($conversationId);
        /** @var AgentExecutionLog $log */
        $log = AgentExecutionLog::where('conversation_id', $conversationId)
            ->findOrFail($agentExecutionLogId);

        $tools = $this->resolveToolsForAgent($agentKey, $conversation);
        $this->assertAllToolsSafe($tools);

        [$history, $prompt] = $this->buildContext($conversation, $log);

        if ($overridePrompt !== null && $overridePrompt !== '') {
            $prompt = $overridePrompt;
        }

        if ($prompt === '') {
            throw new RuntimeException('No se encontró mensaje inbound para reevaluar este turn.');
        }

        $agent = new AnonymousAgent(
            instructions: $draftInstructions,
            messages: $history,
            tools: $tools,
        );

        try {
            app()->instance('ai.replay_mode', true);

            /** @var AgentResponse $response */
            $response = $agent->prompt($prompt);
        } finally {
            app()->forgetInstance('ai.replay_mode');
        }

        return [
            'response' => $response->text,
            'agent_key' => $agentKey,
            'tool_calls' => $this->extractToolCalls($response, $tools),
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
        ];
    }

    /**
     * @return array<int, Tool>
     */
    private function resolveToolsForAgent(string $agentKey, Conversation $conversation): array
    {
        /** @var WhatsAppAdapter $adapter */
        $adapter = app(WhatsAppAdapter::class);
        $coverageTool = new CheckCoverageRuleTool;

        return match ($agentKey) {
            'customer_identifier' => [new IdentifyCustomerTool($adapter, $conversation), $coverageTool],
            'vehicle_identifier' => [new IdentifyVehicleTool($adapter, $conversation), $coverageTool],
            'coverage_preference' => [new CoveragePreferenceTool($adapter, $conversation), $coverageTool],
            'quote_reception' => [new GetQuoteTool($adapter, $conversation), $coverageTool],
            'checkout_closer' => [new CheckoutTool($adapter, $conversation), $coverageTool],
            default => throw new RuntimeException("Agent key desconocido: {$agentKey}"),
        };
    }

    /**
     * Fail-safe: cada tool debe declarar política REAL o implementar Mockable.
     *
     * @param  array<int, Tool>  $tools
     */
    private function assertAllToolsSafe(array $tools): void
    {
        foreach ($tools as $tool) {
            $policy = method_exists($tool, 'replayPolicy')
                ? $tool->replayPolicy()
                : null;

            if ($policy === ReplayPolicy::REAL) {
                continue;
            }

            if ($policy === ReplayPolicy::MOCK && $tool instanceof Mockable) {
                continue;
            }

            throw new RuntimeException(
                'Tool no apta para replay: '.$tool::class.
                ' (debe declarar replayPolicy() = REAL o implementar Mockable).'
            );
        }
    }

    /**
     * Construye historial previo al turn target y extrae el último inbound como prompt.
     *
     * @return array{0: array<int, UserMessage|AssistantMessage>, 1: string}
     */
    private function buildContext(Conversation $conversation, AgentExecutionLog $log): array
    {
        $cutoff = $log->created_at;

        /** @var Collection<int, Message> $messages */
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($cutoff !== null, fn ($q) => $q->where('created_at', '<=', $cutoff))
            ->whereNotNull('content')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return [[], ''];
        }

        // El prompt es el último inbound antes/igual al cutoff; el resto es historia.
        $lastInboundIdx = null;
        foreach ($messages as $idx => $m) {
            if ($m->direction === 'inbound') {
                $lastInboundIdx = $idx;
            }
        }

        if ($lastInboundIdx === null) {
            return [[], ''];
        }

        $prompt = (string) $messages[$lastInboundIdx]->content;
        $history = [];

        foreach ($messages->take($lastInboundIdx) as $m) {
            $content = (string) $m->content;
            $history[] = $m->direction === 'inbound'
                ? new UserMessage($content)
                : new AssistantMessage($content);
        }

        return [$history, $prompt];
    }

    /**
     * @param  array<int, Tool>  $tools
     * @return array<int, array{name: string, arguments: mixed, policy: string}>
     */
    private function extractToolCalls(AgentResponse $response, array $tools): array
    {
        $policyByName = [];
        foreach ($tools as $tool) {
            if (! method_exists($tool, 'replayPolicy')) {
                continue;
            }
            // Nombre convencional del tool = basename de la clase en snake_case; el SDK
            // lo deriva internamente, así que dejamos fallback por si no matchea.
            $policyByName[$tool::class] = $tool->replayPolicy()->value;
        }

        return $response->toolCalls->map(function ($tc) use ($policyByName): array {
            $policy = 'unknown';
            foreach ($policyByName as $class => $p) {
                if (str_contains(strtolower($class), str_replace('_', '', strtolower($tc->name)))) {
                    $policy = $p;
                    break;
                }
            }

            return [
                'name' => $tc->name,
                'arguments' => $tc->arguments,
                'policy' => $policy,
            ];
        })->values()->all();
    }
}
