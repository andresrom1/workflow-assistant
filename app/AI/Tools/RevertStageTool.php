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

class RevertStageTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    /**
     * Flags a invalidar por etapa destino. La cascada invalida la etapa
     * elegida y todas las que le siguen en el pipeline.
     *
     * @var array<string, list<string>>
     */
    private const CASCADE = [
        'customer' => ['customer_identified', 'vehicle_identified', 'coverage_set', 'quote_ready', 'checkout_done'],
        'vehicle' => ['vehicle_identified', 'coverage_set', 'quote_ready', 'checkout_done'],
        'coverage' => ['coverage_set', 'quote_ready', 'checkout_done'],
    ];

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Vuelve la conversación a una etapa anterior cuando el cliente corrige un dato '
            .'ya registrado (cambió de auto, se equivocó de modelo, quiere otra cobertura desde cero). '
            .'Invalida la cotización en curso. NO usar para ajustes dentro de la etapa actual.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()
                ->enum(['customer', 'vehicle', 'coverage'])
                ->description('Etapa a la que hay que volver: customer (datos del cliente), vehicle (datos del auto), coverage (tipo de cobertura).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        $result = $this->adapter->revertStage($request->all(), $this->conversation);

        if ($result['success']) {
            $stage = $request['stage'] ?? null;
            $flags = array_fill_keys(self::CASCADE[$stage] ?? [], false);
            if ($flags !== []) {
                $this->conversation->updateAiState($flags);
            }
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'stage' => $request['stage'] ?? 'vehicle',
            'message' => 'Etapa revertida (mock).',
        ]);
    }
}
