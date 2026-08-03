<?php

namespace App\AI;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Agents\CheckoutAgent;
use App\AI\Agents\CoveragePreferenceAgent;
use App\AI\Agents\CustomerIdentifierAgent;
use App\AI\Agents\QuoteAgent;
use App\AI\Agents\VehicleIdentifierAgent;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\DeclineDniTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\ProvideVehicleFactTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AgentExecutionLog;
use App\Models\AgentPrompt;
use App\Models\Conversation;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Responses\AgentResponse;

class InsuranceOrchestrator
{
    public function __construct(private readonly WhatsAppAdapter $adapter) {}

    /**
     * Punto de entrada principal del orquestador.
     *
     * Lee el estado del flujo desde conversation.metadata.ai_state,
     * selecciona el sub-agente correspondiente al paso actual,
     * lo invoca con memoria persistente y retorna la respuesta de texto.
     *
     * El estado se actualiza dentro de cada Tool al ejecutarse exitosamente,
     * por lo que en el próximo mensaje el orquestador derivará al siguiente agente.
     *
     * @return array{text: string, agent: string, execution_log_ids: int[], buttons: list<array{id: string, title: string}>|null, public_link: string|null}
     */
    public function handle(string $message, Conversation $conversation): array
    {
        // Si el cliente ya vinculado (materializado en la ingesta) tiene DNI, saltear el paso
        // de identificación. La captura de teléfono/nombre ocurre en la ingesta, no acá.
        $this->syncCustomerIdentifiedState($conversation);

        $stateBefore = $conversation->aiState();
        $step = $this->stepFromState($stateBefore);

        // Todos los agentes comparten el mismo hilo de conversación, identificado por
        // el id interno de la conversación (estable e inmutable — no depende del teléfono
        // ni del BSUID, que pueden cambiar o faltar). Esto permite que el contexto fluya
        // entre agentes. Ver docs: la memoria del SDK se keyea acá, no en el canal.
        $waUser = (object) ['id' => $conversation->id];
        $agent = $this->resolveAgent($stateBefore, $conversation);

        $start = hrtime(true);

        try {
            /** @var AgentResponse $response */
            $response = $agent->continueLastConversation($waUser)->prompt($message);
        } catch (\Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $start) / 1e6);

            AgentExecutionLog::create([
                'conversation_id' => $conversation->id,
                'agent_name' => class_basename($agent),
                'agent_prompt_id' => $this->resolveAgentPromptId($agent),
                'step' => $step,
                'state_before' => $stateBefore,
                'state_after' => $stateBefore,
                'state_changes' => [],
                'chained' => false,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'duration_ms' => $durationMs,
                'inbound_message_ids' => null,
                'outbound_message_id' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'tool_calls' => null,
            ]);

            throw $e;
        }

        $durationMs = (int) round((hrtime(true) - $start) / 1e6);

        // Detectar transición de estado: si quote_ready flipeó durante la ejecución,
        // descartar la respuesta de QuoteAgent y encadenar a CheckoutAgent.
        $conversation->refresh();
        $stateAfter = $conversation->aiState();
        $stateChanges = array_filter(
            $stateAfter,
            fn (bool $val, string $key): bool => $val !== ($stateBefore[$key] ?? false),
            ARRAY_FILTER_USE_BOTH
        );

        if (! $stateBefore['quote_ready'] && $stateAfter['quote_ready']) {
            $quoteLog = AgentExecutionLog::create([
                'conversation_id' => $conversation->id,
                'agent_name' => class_basename($agent),
                'agent_prompt_id' => $this->resolveAgentPromptId($agent),
                'step' => $step,
                'state_before' => $stateBefore,
                'state_after' => $stateAfter,
                'state_changes' => $stateChanges,
                'chained' => true,
                'status' => 'success',
                'duration_ms' => $durationMs,
                'inbound_message_ids' => null,
                'outbound_message_id' => null,
                'input_tokens' => $this->extractInputTokens($response),
                'output_tokens' => $this->extractOutputTokens($response),
                'tool_calls' => $this->extractToolCalls($response),
            ]);

            $checkoutStateBefore = $stateAfter;
            $checkoutAgent = $this->resolveAgent($checkoutStateBefore, $conversation);
            $checkoutStep = $this->stepFromState($checkoutStateBefore);
            $checkoutStart = hrtime(true);

            /** @var AgentResponse $checkoutResponse */
            $checkoutResponse = $checkoutAgent->continueLastConversation($waUser)->prompt('Cotizaciones listas');
            $checkoutDurationMs = (int) round((hrtime(true) - $checkoutStart) / 1e6);

            $conversation->refresh();
            $checkoutStateAfter = $conversation->aiState();
            $checkoutStateChanges = array_filter(
                $checkoutStateAfter,
                fn (bool $val, string $key): bool => $val !== ($checkoutStateBefore[$key] ?? false),
                ARRAY_FILTER_USE_BOTH
            );

            $checkoutLog = AgentExecutionLog::create([
                'conversation_id' => $conversation->id,
                'agent_name' => class_basename($checkoutAgent),
                'agent_prompt_id' => $this->resolveAgentPromptId($checkoutAgent),
                'step' => $checkoutStep,
                'state_before' => $checkoutStateBefore,
                'state_after' => $checkoutStateAfter,
                'state_changes' => $checkoutStateChanges,
                'chained' => false,
                'status' => 'success',
                'duration_ms' => $checkoutDurationMs,
                'inbound_message_ids' => null,
                'outbound_message_id' => null,
                'input_tokens' => $this->extractInputTokens($checkoutResponse),
                'output_tokens' => $this->extractOutputTokens($checkoutResponse),
                'tool_calls' => $this->extractToolCalls($checkoutResponse),
            ]);

            $pendienteEncadenado = $this->pullPending($conversation);

            return [
                'text' => $checkoutResponse->text,
                'agent' => class_basename($checkoutAgent),
                'execution_log_ids' => [$quoteLog->id, $checkoutLog->id],
                'buttons' => $pendienteEncadenado['buttons'],
                'public_link' => $pendienteEncadenado['public_link'],
            ];
        }

        $log = AgentExecutionLog::create([
            'conversation_id' => $conversation->id,
            'agent_name' => class_basename($agent),
            'agent_prompt_id' => $this->resolveAgentPromptId($agent),
            'step' => $step,
            'state_before' => $stateBefore,
            'state_after' => $stateAfter,
            'state_changes' => $stateChanges,
            'chained' => false,
            'status' => 'success',
            'duration_ms' => $durationMs,
            'inbound_message_ids' => null,
            'outbound_message_id' => null,
            'input_tokens' => $this->extractInputTokens($response),
            'output_tokens' => $this->extractOutputTokens($response),
            'tool_calls' => $this->extractToolCalls($response),
        ]);

        $pendiente = $this->pullPending($conversation);

        return [
            'text' => $response->text,
            'agent' => class_basename($agent),
            'execution_log_ids' => [$log->id],
            'buttons' => $pendiente['buttons'],
            'public_link' => $pendiente['public_link'],
        ];
    }

    /**
     * Levanta y limpia lo que una tool (ej. PresentQuoteOptionsTool) haya dejado pendiente en
     * metadata durante este turno: los botones que acompañan al mensaje y el link a la vista
     * pública de la cotización, que sale en un mensaje aparte inmediatamente después.
     *
     * @return array{buttons: list<array{id: string, title: string}>|null, public_link: string|null}
     */
    private function pullPending(Conversation $conversation): array
    {
        $conversation->refresh();
        $meta = $conversation->metadata ?? [];

        $buttons = data_get($meta, 'pending_interactive.buttons');
        $publicLink = data_get($meta, 'pending_public_link');

        if ($buttons !== null || $publicLink !== null) {
            unset($meta['pending_interactive'], $meta['pending_public_link']);
            $conversation->update(['metadata' => $meta]);
        }

        return ['buttons' => $buttons, 'public_link' => $publicLink];
    }

    /**
     * Gate de estado: si el cliente ya vinculado a la conversación tiene DNI (identidad de
     * dominio), marca el paso de identificación como resuelto para saltear el
     * CustomerIdentifierAgent (cliente recurrente ya identificado).
     *
     * La materialización del Customer y la captura de teléfono/nombre ocurren en la ingesta
     * ({@see ProcessWhatsAppMessage}), no acá. Este método NO crea ni identifica:
     * solo lee el DNI y prende el flag. Si no hay DNI, el paso queda abierto para que el
     * agente lo pida — cotizar con un DNI inventado rompe la emisión (ROADMAP 2026-07-19);
     * `DeclineDniTool` es la salida para el cliente que no lo quiere dar.
     */
    private function syncCustomerIdentifiedState(Conversation $conversation): void
    {
        if ($conversation->aiState()['customer_identified']) {
            return;
        }

        if ($conversation->customer?->dni) {
            $conversation->updateAiState(['customer_identified' => true]);
        }
    }

    /**
     * Mapea la clase del agente a la clave de su prompt activo y devuelve el id
     * de la versión que estaba activa al momento de esta ejecución. Si no hay
     * prompt en DB para esa clave (p.e. QuoteAgent que corre contra el .md), devuelve null.
     */
    private function resolveAgentPromptId(Agent $agent): ?int
    {
        $key = match (class_basename($agent)) {
            'CustomerIdentifierAgent' => 'customer_identifier',
            'VehicleIdentifierAgent' => 'vehicle_identifier',
            'CoveragePreferenceAgent' => 'coverage_preference',
            'QuoteAgent' => 'quote_reception',
            'CheckoutAgent' => 'checkout_closer',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        return AgentPrompt::activeFor($key)?->id;
    }

    /**
     * Mapea el ai_state al ordinal del paso activo (1–5).
     *
     * @param  array<string, bool>  $state
     */
    private function stepFromState(array $state): int
    {
        return match (true) {
            ! $state['customer_identified'] => 1,
            ! $state['vehicle_identified'] => 2,
            ! $state['coverage_set'] => 3,
            ! $state['quote_ready'] => 4,
            default => 5,
        };
    }

    /**
     * Extrae los tool calls del AgentResponse para persistirlos en el log.
     *
     * @return array<int, array{name: string, arguments: mixed}>
     */
    private function extractToolCalls(AgentResponse $response): array
    {
        return $response->toolCalls
            ->map(fn ($tc) => ['name' => $tc->name, 'arguments' => $tc->arguments])
            ->values()
            ->all();
    }

    /**
     * Extrae tokens de entrada del AgentResponse.
     * Retorna null si el SDK reportó 0 (valor por defecto — no trackeado).
     */
    private function extractInputTokens(AgentResponse $response): ?int
    {
        $tokens = $response->usage->promptTokens;

        return $tokens > 0 ? $tokens : null;
    }

    /**
     * Extrae tokens de salida del AgentResponse.
     * Retorna null si el SDK reportó 0 (valor por defecto — no trackeado).
     */
    private function extractOutputTokens(AgentResponse $response): ?int
    {
        $tokens = $response->usage->completionTokens;

        return $tokens > 0 ? $tokens : null;
    }

    /**
     * Selecciona el sub-agente activo según el estado actual del flujo.
     *
     * El orden respeta la secuencia del proceso de cotización:
     * cliente → vehículo → cobertura → cotización → checkout.
     */
    private function resolveAgent(array $state, Conversation $conversation): Agent&Conversational
    {
        $coverageTool = new CheckCoverageRuleTool;
        // Disponible desde cobertura en adelante — no en CustomerIdentifier ni
        // VehicleIdentifier, cuyas etapas no tienen una anterior a la que volver.
        $revertTool = new RevertStageTool($this->adapter, $conversation);
        // Disponible en las 5 etapas: un siniestro puede reportarse en cualquier momento.
        $siniestroTool = new SiniestroGuidanceTool($conversation);

        return match (true) {
            ! $state['customer_identified'] => new CustomerIdentifierAgent(
                new IdentifyCustomerTool($this->adapter, $conversation),
                $coverageTool,
                $siniestroTool,
                new DeclineDniTool($conversation),
            ),
            ! $state['vehicle_identified'] => new VehicleIdentifierAgent(
                new IdentifyVehicleTool($this->adapter, $conversation),
                $coverageTool,
                $siniestroTool,
            ),
            ! $state['coverage_set'] => new CoveragePreferenceAgent(
                new CoveragePreferenceTool($this->adapter, $conversation),
                $coverageTool,
                new ProvideVehicleFactTool($this->adapter, $conversation),
                $revertTool,
                $siniestroTool,
            ),
            ! $state['quote_ready'] => new QuoteAgent(
                new GetQuoteTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
                $siniestroTool,
            ),
            default => new CheckoutAgent(
                new CheckoutTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
                new PresentQuoteOptionsTool($conversation),
                $siniestroTool,
            ),
        };
    }
}
