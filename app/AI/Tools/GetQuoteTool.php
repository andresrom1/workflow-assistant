<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetQuoteTool implements Tool
{
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
                ->description('ID de la cotización a consultar (provisto por identify_vehicle).'),
        ];
    }

    public function handle(Request $request): string
    {
        $result = $this->adapter->getQuote($request->all());

        if ($result['success']) {
            $this->conversation->updateAiState(['quote_ready' => true]);
        }

        return json_encode($result);
    }
}
