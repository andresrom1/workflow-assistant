<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Concerns\HasMockReplay;
use App\AI\Contracts\Mockable;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class IdentifyVehicleTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Registra el vehículo del cliente en el sistema e inicia una cotización pendiente. '
            .'Usar cuando el usuario proporcione los datos completos de su auto.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patente' => $schema->string()
                ->description('Patente del vehículo (formato ABC123 o AB123CD).')
                ->required(),
            'marca' => $schema->string()
                ->description('Marca del vehículo (ej: Toyota, Ford, Chevrolet).')
                ->required(),
            'modelo' => $schema->string()
                ->description('Modelo del vehículo (ej: Corolla, Focus, Onix).')
                ->required(),
            'version' => $schema->string()
                ->description('Versión o trim del vehículo (ej: XEI, SE, LTZ).')
                ->required(),
            'year' => $schema->integer()
                ->description('Año de fabricación del vehículo (ej: 2019).')
                ->required(),
            'combustible' => $schema->string()->enum(['nafta', 'diesel', 'gnc', 'electrico', 'hibrido'])
                ->description('Tipo de combustible del vehículo.')
                ->required(),
            'codigo_postal' => $schema->string()
                ->description('Código postal donde se guarda habitualmente el vehículo.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        // El sessionUuid se genera aquí porque en el flujo WhatsApp no existe
        // un identificador de sesión del front-end como en el flujo web/OpenAI.
        $result = $this->adapter->identifyVehicle(
            array_merge($request->all(), [
                'channel' => 'whatsapp',
                'sessionUuid' => (string) Str::uuid(),
            ]),
            $this->conversation
        );

        if ($result['success']) {
            $this->conversation->updateAiState(['vehicle_identified' => true]);
        }

        return json_encode($result);
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'vehicle' => [
                'id' => 0,
                'patente' => $request['patente'] ?? 'ABC123',
                'marca' => $request['marca'] ?? 'Toyota',
                'modelo' => $request['modelo'] ?? 'Corolla',
                'version' => $request['version'] ?? 'XEI',
                'year' => $request['year'] ?? 2020,
                'combustible' => $request['combustible'] ?? 'nafta',
                'codigo_postal' => $request['codigo_postal'] ?? '1000',
            ],
            'quote_id' => 0,
            'message' => 'Vehículo registrado y cotización iniciada (mock). Las aseguradoras tardan unos segundos.',
        ]);
    }
}
