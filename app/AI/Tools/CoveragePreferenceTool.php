<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CoveragePreferenceTool implements Tool
{
    use ConditionalLogger;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra la preferencia de cobertura del cliente para su vehículo y dispara la cotización. '
            .'Usar cuando el usuario haya elegido el tipo de cobertura que desea.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patente' => $schema->string()
                ->description('Patente del vehículo para el que se registra la preferencia.')
                ->required(),
            'preference' => $schema->string()
                ->description('Tipo de cobertura elegida por el cliente (ej: terceros, terceros_completo, todo_riesgo).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
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
}
