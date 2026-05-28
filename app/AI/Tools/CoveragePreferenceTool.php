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

class CoveragePreferenceTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra la cobertura elegida (A=Responsabilidad Civil, B=Robo/Incendio Total, '
            .'C=Terceros Completos, D=Todo Riesgo) para el vehículo del cliente. '
            .'Usar cuando el usuario haya decidido qué nivel de protección desea.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'coverage_code' => $schema->string()
                ->enum(['A', 'B', 'C', 'D'])
                ->description('Código de cobertura elegida: A=Responsabilidad Civil, B=Robo/Incendio Total, C=Terceros Completos, D=Todo Riesgo.')
                ->required(),
            'patente' => $schema->string()
                ->description('Patente del vehículo para el que se registra la preferencia.')
                ->required(),
            'reasoning' => $schema->string()
                ->description("Razón de la elección. Ej: 'Cliente eligió C porque no quiere pagar franquicia'.")
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        $result = $this->adapter->coveragePreference(
            $request->all(),
            $this->conversation
        );

        if ($result['success']) {
            $this->conversation->updateAiState(['coverage_set' => true]);
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'coverage_code' => $request['coverage_code'] ?? 'C',
            'patente' => $request['patente'] ?? 'ABC123',
            'message' => 'Preferencia de cobertura registrada (mock).',
        ]);
    }
}
