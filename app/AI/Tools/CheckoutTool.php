<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CheckoutTool implements Tool
{
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
                ->description('ID de la cotización seleccionada.'),
            'quote_alternative_id' => $schema->integer()
                ->description('ID de la alternativa de cobertura elegida por el cliente.'),
        ];
    }

    public function handle(Request $request): string
    {
        $result = $this->adapter->checkout(
            $request->all(),
            $this->conversation
        );

        if ($result['success']) {
            $this->conversation->updateAiState(['checkout_done' => true]);
        }

        return json_encode($result);
    }
}
