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

class IdentifyCustomerTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra la identidad del cliente cuando proporciona su DNI/CUIT o su email. '
            .'El teléfono NO va acá: ya se toma automáticamente del número de WhatsApp.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier_type' => $schema->string()->enum(['email', 'dni'])
                ->description('Tipo de identificador provisto por el usuario: email o dni (DNI/CUIT).')
                ->required(),
            'identifier_value' => $schema->string()
                ->description('El valor del identificador (ej: usuario@ejemplo.com, 30304050).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        // La conversación (y su BSUID/customer) van inyectados en $this->conversation; el
        // payload solo lleva el identificador que dio el usuario (email o DNI). Se entra por
        // handleToolCall() y no por el handler directo: es el único lugar que loguea el motivo
        // real si algo falla (ver su docblock).
        $result = $this->adapter->handleToolCall($request->all(), 'identify_customer', $this->conversation);

        if ($result['success']) {
            $this->conversation->refresh();

            // El flag solo se prende si el customer YA tiene DNI. Si este identify()
            // fue por email/phone (o el DNI que se dio no pasó validación), el paso
            // de identificación sigue abierto — el agente tiene que pedir el DNI o
            // el cliente declinarlo explícitamente (DeclineDniTool). Mismo criterio
            // que InsuranceOrchestrator::tryAutoIdentifyByPhone. Ver ROADMAP Bitácora
            // 2026-07-19.
            if ($this->conversation->customer?->dni) {
                $this->conversation->updateAiState(['customer_identified' => true]);
            }
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        $type = $request['identifier_type'] ?? 'phone';
        $value = $request['identifier_value'] ?? 'N/A';

        return json_encode([
            'success' => true,
            'mock' => true,
            'customer' => [
                'id' => 0,
                'name' => 'Cliente de replay',
                'email' => $type === 'email' ? $value : 'replay@example.com',
                'phone' => $type === 'phone' ? $value : '1100000000',
            ],
            'message' => "Cliente identificado por {$type} (mock).",
        ]);
    }
}
