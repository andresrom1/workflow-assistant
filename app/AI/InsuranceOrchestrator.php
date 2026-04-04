<?php

namespace App\AI;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Agents\CheckoutAgent;
use App\AI\Agents\CoveragePreferenceAgent;
use App\AI\Agents\CustomerIdentifierAgent;
use App\AI\Agents\QuoteAgent;
use App\AI\Agents\VehicleIdentifierAgent;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
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
     */
    public function handle(string $message, Conversation $conversation): string
    {
        // Identificar al cliente automáticamente usando el teléfono del canal (si está disponible).
        // Esto evita pedirle al usuario su número cuando ya lo tenemos del webhook.
        $this->tryAutoIdentifyByPhone($conversation);

        $state = $conversation->aiState();

        // Todos los agentes comparten el mismo hilo de conversación, identificado
        // por el wa_id del usuario. Esto permite que el contexto fluya entre agentes.
        $waUser = (object) ['id' => $conversation->external_conversation_id];

        $agent = $this->resolveAgent($state, $conversation);

        /** @var AgentResponse $response */
        $response = $agent->continueLastConversation($waUser)->prompt($message);

        return $response->text;
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
        }
    }

    /**
     * Selecciona el sub-agente activo según el estado actual del flujo.
     *
     * El orden respeta la secuencia del proceso de cotización:
     * cliente → vehículo → cobertura → cotización → checkout.
     */
    private function resolveAgent(array $state, Conversation $conversation): Agent&Conversational
    {
        return match (true) {
            ! $state['customer_identified'] => new CustomerIdentifierAgent(
                new IdentifyCustomerTool($this->adapter, $conversation)
            ),
            ! $state['vehicle_identified'] => new VehicleIdentifierAgent(
                new IdentifyVehicleTool($this->adapter, $conversation)
            ),
            ! $state['coverage_set'] => new CoveragePreferenceAgent(
                new CoveragePreferenceTool($this->adapter, $conversation)
            ),
            ! $state['quote_ready'] => new QuoteAgent(
                new GetQuoteTool($this->adapter, $conversation)
            ),
            default => new CheckoutAgent(
                new CheckoutTool($this->adapter, $conversation)
            ),
        };
    }
}
