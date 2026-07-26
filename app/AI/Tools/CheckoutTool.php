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

class CheckoutTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Genera el link de checkout para que el cliente complete la contratación de la póliza. '
            .'Usar cuando el cliente haya seleccionado una alternativa de cobertura específica.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quoteId' => $schema->integer()
                ->description('ID de la cotización seleccionada.')
                ->required(),
            'quote_alternative_id' => $schema->integer()
                ->description('ID de la alternativa de cobertura elegida por el cliente.')
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
            'checkout',
            $this->conversation
        );

        if ($result['success']) {
            $this->conversation->updateAiState(['checkout_done' => true]);
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'quoteId' => $request['quoteId'] ?? 0,
            'quote_alternative_id' => $request['quote_alternative_id'] ?? 0,
            'checkout_url' => 'https://example.test/checkout/MOCK-TOKEN',
            'message' => 'Link de checkout generado (mock).',
        ]);
    }
}
