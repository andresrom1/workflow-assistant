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
                ->description('Código de cobertura elegida: A=Responsabilidad Civil, B=Robo/Incendio Total, C=Terceros Completos, D=Todo Riesgo. '
                    .'Si el cliente pidió varios niveles, poné acá el principal y la lista completa en coverage_codes.')
                ->required(),
            'coverage_codes' => $schema->array()
                ->items($schema->string())
                ->description('TODOS los niveles que el cliente pidió, cuando nombró más de uno: '
                    .'"terceros completo y todo riesgo para comparar" son ["C","D"]. '
                    .'Llamá la tool UNA sola vez con la lista completa, no una vez por nivel. '
                    .'Si cambió de opinión, volvé a llamarla con la lista nueva: reemplaza a la anterior. '
                    .'Podés omitirlo cuando pidió un solo nivel.'),
            'patente' => $schema->string()
                ->description('Patente del vehículo para el que se registra la preferencia.')
                ->required(),
            'reasoning' => $schema->string()
                ->description("Razón de la elección. Ej: 'Cliente eligió C porque no quiere pagar franquicia'.")
                ->required(),
            'coberturas_requeridas' => $schema->array()
                ->items($schema->string())
                ->description('Coberturas concretas que el cliente pidió por su nombre, además del nivel. '
                    ."Usá el vocabulario del proveedor: 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras', "
                    ."'Inundación', 'Auxilio mecánico y/o Grúa'. Vacío si solo nombró el nivel de cobertura. "
                    .'El agente que presenta las cotizaciones descarta las alternativas que no las incluyan, '
                    .'así que no agregues nada que el cliente no haya pedido.'),
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
            'coverage_preference',
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
