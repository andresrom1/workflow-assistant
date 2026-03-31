<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class IdentifyVehicleTool implements Tool
{
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
                ->description('Patente del vehículo (formato ABC123 o AB123CD).'),
            'marca' => $schema->string()
                ->description('Marca del vehículo (ej: Toyota, Ford, Chevrolet).'),
            'modelo' => $schema->string()
                ->description('Modelo del vehículo (ej: Corolla, Focus, Onix).'),
            'version' => $schema->string()
                ->description('Versión o trim del vehículo (ej: XEI, SE, LTZ).'),
            'year' => $schema->integer()
                ->description('Año de fabricación del vehículo (ej: 2019).'),
            'combustible' => $schema->string()->enum(['nafta', 'diesel', 'gnc', 'electrico', 'hibrido'])
                ->description('Tipo de combustible del vehículo.'),
            'codigo_postal' => $schema->string()
                ->description('Código postal donde se guarda habitualmente el vehículo.'),
        ];
    }

    public function handle(Request $request): string
    {
        // El sessionUuid se genera aquí porque en el flujo WhatsApp no existe
        // un identificador de sesión del front-end como en el flujo web/OpenAI.
        $result = $this->adapter->identifyVehicle(
            array_merge($request->all(), [
                'external_conversation_id' => $this->conversation->external_conversation_id,
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
}
