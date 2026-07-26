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

class GetQuoteTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Obtiene las alternativas de cotización disponibles para mostrarle al cliente. '
            .'Usar cuando la cotización ya fue procesada y se quieren mostrar los resultados.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quoteId' => $schema->integer()
                ->description('ID de la cotización a consultar (provisto por identify_vehicle).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        $result = $this->adapter->handleToolCall($request->all(), 'get_quote', $this->conversation);

        if ($result['success']) {
            $this->conversation->updateAiState(['quote_ready' => true]);
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'quoteId' => $request['quoteId'] ?? 0,
            'alternatives' => [
                ['insurer' => 'Aseguradora A (mock)', 'premium_monthly' => 45000, 'coverage_code' => 'C'],
                ['insurer' => 'Aseguradora B (mock)', 'premium_monthly' => 52000, 'coverage_code' => 'C'],
                ['insurer' => 'Aseguradora C (mock)', 'premium_monthly' => 38000, 'coverage_code' => 'C'],
            ],
            'message' => 'Alternativas de cotización listas (mock).',
        ]);
    }
}
