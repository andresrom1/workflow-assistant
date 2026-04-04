<?php

namespace App\AI\Tools;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class IdentifyCustomerTool implements Tool
{
    use ConditionalLogger;

    public function __construct(
        private readonly WhatsAppAdapter $adapter,
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Identifica o crea un cliente en el sistema y lo vincula a la conversación activa. '
            .'Usar cuando el usuario proporcione su email, número de teléfono o DNI/CUIT.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier_type' => $schema->string()->enum(['email', 'phone', 'wbid'])
                ->description('Tipo de identificador provisto por el usuario: email, phone (teléfono) o wbid (DNI/CUIT).')
                ->required(),
            'identifier_value' => $schema->string()
                ->description('El valor del identificador (ej: usuario@ejemplo.com, 1150001234, 20304050607).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        $result = $this->adapter->identifyCustomer(
            array_merge($request->all(), [
                'external_conversation_id' => $this->conversation->external_conversation_id,
                'ext_user_id' => $this->conversation->external_conversation_id,
                'channel' => 'whatsapp',
            ]),
            $this->conversation
        );

        if ($result['success']) {
            $this->conversation->updateAiState(['customer_identified' => true]);
        }

        return json_encode($result);
    }
}
