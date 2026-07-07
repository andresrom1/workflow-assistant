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
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\ProvideVehicleFactTool;
use App\AI\Tools\RevertStageTool;
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
     * @return array{text: string, agent: string, execution_log_ids: int[], buttons: list<array{id: string, title: string}>|null}
     */
    public function handle(string $message, Conversation $conversation): array
    {
        // Identificar al cliente automáticamente usando el teléfono del canal (si está disponible).
        // Esto evita pedirle al usuario su número cuando ya lo tenemos del webhook.
        $this->tryAutoIdentifyByPhone($conversation);

        $stateBefore = $conversation->aiState();
        $step = $this->stepFromState($stateBefore);

        // Todos los agentes comparten el mismo hilo de conversación, identificado
        // por el wa_id del usuario. Esto permite que el contexto fluya entre agentes.
        $waUser = (object) ['id' => $conversation->external_conversation_id];
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

            return [
                'text' => $checkoutResponse->text,
                'agent' => class_basename($checkoutAgent),
                'execution_log_ids' => [$quoteLog->id, $checkoutLog->id],
                'buttons' => $this->pullPendingInteractive($conversation),
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

        return [
            'text' => $response->text,
            'agent' => class_basename($agent),
            'execution_log_ids' => [$log->id],
            'buttons' => $this->pullPendingInteractive($conversation),
        ];
    }

    /**
     * Levanta y limpia los botones que una tool (ej. PresentQuoteOptionsTool)
     * haya dejado pendientes en metadata durante este turno.
     *
     * @return list<array{id: string, title: string}>|null
     */
    private function pullPendingInteractive(Conversation $conversation): ?array
    {
        $conversation->refresh();
        $meta = $conversation->metadata ?? [];
        $buttons = data_get($meta, 'pending_interactive.buttons');

        if ($buttons !== null) {
            unset($meta['pending_interactive']);
            $conversation->update(['metadata' => $meta]);
        }

        return $buttons;
    }

    /**
     * Identifica al cliente automáticamente usando el identificador de conversación
     * (número de teléfono) si aún no fue identificado y el identificador es numérico.
     *
     * Si el external_conversation_id es un BSUID alfanumérico (usuario con username
     * sin número de teléfono visible), se omite y el CustomerIdentifierAgent
     * solicitará los datos al usuario.
     *
     * Cadena de delegación:
     * → WhatsAppAdapter::identifyCustomer()        (normaliza/valida el payload)
     * → CustomerIdentificationService::findOrCreate() (lógica de negocio)
     * → CustomerRepository                          (acceso a datos)
     */
    private function tryAutoIdentifyByPhone(Conversation $conversation): void
    {
        if ($conversation->aiState()['customer_identified']) {
            return;
        }

        $waId = $conversation->external_conversation_id;

        // Solo proceder si el identificador es un número de teléfono (no un BSUID alfanumérico).
        if (! preg_match('/^\d{7,15}$/', $waId)) {
            return;
        }

        $result = $this->adapter->identifyCustomer([
            'identifier_type' => 'phone',
            'identifier_value' => $waId,
            'external_conversation_id' => $conversation->external_conversation_id,
            'channel' => 'whatsapp',
        ], $conversation);

        if ($result['success']) {
            $conversation->updateAiState(['customer_identified' => true]);
            $conversation->refresh();

            // El customer recién creado por teléfono no tiene nombre; usar el
            // profile name del webhook para que el saludo del primer turno sea personal.
            $customer = $conversation->customer;
            if ($customer && ! $customer->name) {
                $senderName = $conversation->messages()
                    ->where('direction', 'inbound')
                    ->whereNotNull('sender_name')
                    ->latest()
                    ->value('sender_name');

                if ($senderName) {
                    $customer->update(['name' => $senderName]);
                }
            }
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

        return match (true) {
            ! $state['customer_identified'] => new CustomerIdentifierAgent(
                new IdentifyCustomerTool($this->adapter, $conversation),
                $coverageTool,
            ),
            ! $state['vehicle_identified'] => new VehicleIdentifierAgent(
                new IdentifyVehicleTool($this->adapter, $conversation),
                $coverageTool,
            ),
            ! $state['coverage_set'] => new CoveragePreferenceAgent(
                new CoveragePreferenceTool($this->adapter, $conversation),
                $coverageTool,
                new ProvideVehicleFactTool($this->adapter, $conversation),
                $revertTool,
            ),
            ! $state['quote_ready'] => new QuoteAgent(
                new GetQuoteTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
            ),
            default => new CheckoutAgent(
                new CheckoutTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
                new PresentQuoteOptionsTool($conversation),
            ),
        };
    }
}
