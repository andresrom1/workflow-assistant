<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Concerns\HasMockReplay;
use App\AI\Contracts\Mockable;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ProvideVehicleFactTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra un dato del vehículo que quedó pendiente para poder cotizar '
            .'(ej: transmisión automática/manual) y reintenta la cotización. '
            .'Usar cuando el cliente responde a una pregunta sobre un dato de su auto.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patente' => $schema->string()
                ->description('Patente del vehículo.')
                ->required(),
            'fact' => $schema->string()
                ->description('El dato que respondió el cliente, textual (ej: "automática", "manual", "1.6").')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        $result = $this->adapter->handleToolCall(
            $request->all(),
            'provide_vehicle_fact',
            $this->conversation
        );

        // No toca ai_state: vehicle_identified ya está en true desde identify_vehicle.
        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'patente' => $request['patente'] ?? 'ABC123',
            'fact' => $request['fact'] ?? 'automática',
            'quote_id' => 0,
            'message' => 'Dato del vehículo registrado y cotización iniciada (mock).',
        ]);
    }
}
