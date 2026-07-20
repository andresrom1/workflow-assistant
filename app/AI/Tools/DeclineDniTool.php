<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasMockReplay;
use App\AI\Contracts\Mockable;
use App\Models\Conversation;
use App\Services\Visred\VisredQuotationProvider;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * El cliente prefirió NO dar su DNI/CUIT tras la explicación del motivo. Cierra
 * el paso de identificación igual — sin esto, un cliente que se niega queda
 * repreguntado para siempre (el agente no tiene otra forma de avanzar). NO
 * inventa un DNI: cotizar sin uno omite `person_holder`
 * ({@see VisredQuotationProvider}) y pierde San
 * Cristóbal/Galicia en esa cotización puntual, pero la emisión no se rompe —
 * ver ROADMAP Bitácora 2026-07-19.
 *
 * Guard: si todavía no hay NINGÚN customer vinculado a la conversación (caso
 * BSUID-only sin teléfono numérico — `tryAutoIdentifyByPhone` no corrió), NO
 * cierra el paso: hace falta `identify_customer` con email o teléfono primero,
 * o el cliente queda contra la pared en el paso siguiente
 * (`WhatsAppAdapter::identifyVehicle` → `missing_customer`).
 */
class DeclineDniTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra que el cliente prefirió NO dar su DNI/CUIT después de que se le explicó '
            .'el motivo. Usar SOLO tras una negativa explícita del cliente, nunca por inferencia. '
            .'Avanza igual a la siguiente etapa — nunca inventar ni asumir un DNI.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'motivo' => $schema->string()
                ->description('Motivo que dio el cliente, textual, si mencionó alguno. Vacío si no dijo por qué.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        if ($this->conversation->customer_id === null) {
            return json_encode([
                'success' => false,
                'error' => 'Todavía no identifiqué al cliente por ningún medio (ni email ni teléfono). '
                    .'Pedile uno de esos dos antes de avanzar.',
                'error_code' => 'missing_customer',
            ]);
        }

        $this->conversation->updateAiState(['customer_identified' => true]);
        $this->conversation->update([
            'metadata' => array_merge($this->conversation->metadata ?? [], [
                'dni_declined_at' => now()->toIso8601String(),
            ]),
        ]);

        return json_encode([
            'success' => true,
            'tool_output' => 'Registrado: el cliente prefirió no dar el DNI. Avanzá a la siguiente etapa sin volver a pedirlo.',
        ]);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'tool_output' => 'Registrado (mock): el cliente prefirió no dar el DNI.',
        ]);
    }
}
