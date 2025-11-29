<?php

namespace App\Adapters\OpenAI;

use App\Contracts\AIProviderAdapterInterface;
use App\Repositories\ConversationRepository;
use App\Services\CustomerIdentificationService;
use App\Services\VehicleIdentificationService;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use App\Models\Conversation;

class AgentToolAdapter implements AIProviderAdapterInterface
{
    use ConditionalLogger;

    public function __construct(
        private readonly CustomerIdentificationService $customerService,
        private readonly VehicleIdentificationService $vehicleService,
        private readonly ConversationRepository $conversationRepo,
    ) {}

    /**
     * Implementación obligatoria del método handleToolCall (ENTRY POINT).
     * Este método recibe el payload y delega la ejecución al método de herramienta correspondiente.
     * @param array $payload Datos del request HTTP (body)
     * @param string $toolName Nombre de la herramienta a ejecutar
     * @return array La respuesta formateada para el proveedor de IA
     */
    public function handleToolCall(array $payload, $toolName): array
    {
        // 1. Extraemos datos del contexto
        $openaiUserId = $payload['openai_user_id'] ?? null; //Posiblemente esto nunca sea necesario
        $threadId = $payload['thread_id'] ?? null;
        
        if (empty($threadId)) {
            throw new InvalidArgumentException("Falta el thread_id para establecer el contexto de la conversación.");
        }
        // Nunca deberia ser empty ya que se valida en el controller

        // 2. RESOLVER EL CONTEXTO (Elevado al Orquestador - ÚNICO LUGAR)

        /** @var Conversation $conversation */
        $conversation = $this->conversationRepo->findOrCreateByThreadId($threadId);

        // 3. Logueamos y despachamos
        $this->logCustomer("HTTP Tool Request recibido: {$toolName}", ['payload' => $payload, 'conversation_id' => $conversation->id]);

        try {
            return match ($toolName) {
                'identify_customer' => $this->IdentifyCustomer($payload, $conversation),
                'identify_vehicle' => $this->IdentifyVehicle($payload, $openaiUserId),
                default => $this->formatError("Herramienta no soportada: {$toolName}", 'tool_not_found'),
            };
        } catch (InvalidArgumentException $e) {
            Log::warning('Validación fallida en Adapter', ['error' => $e->getMessage()]);
            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Exception $e) {
            Log::error('Server Error en Adapter', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->formatError('Error interno del servidor', 'server_error');
        }
    }
    
    /**
     * @param array $payload
     * @param Conversation $conversation
     * @return array 
     */
    protected function IdentifyCustomer(array $payload, Conversation $conversation): array
    {
        $this->logCustomer('Adapter: Iniciando identificación de cliente', $payload);
        
        try {
            // Validamos 
            $data = $this->validateCustomer($payload);
            
            // Llamar al service (ahora devuelve array)
            $result = $this->customerService->identify(
                $data['identifier_type'], 
                $data['identifier_value'], 
                $data['thread_id'],
                $conversation);
            $this->logCustomer('Adapter: Resultado de servicio de identificacion recibido en adaptador', $result);
            
            // El array ya viene en formato “OpenAI-friendly”
            return array_merge(['success' => true], $result);

        } catch (InvalidArgumentException $e) {
            Log::warning('Validación', ['error' => $e->getMessage()]);
            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Exception $e) {
            Log::error('Server', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->formatError('Error interno', 'server_error');
        }
    }

    /**
     * @param array $arguments
     * @param string|null $openaiUserId
     * @return array
     */
    protected function IdentifyVehicle(array $arguments, ?string $openaiUserId): array
    {
        // El AgentToolAdapter ya validó que $openaiUserId no sea nulo si es necesario
        if (empty($openaiUserId)) {
            throw new InvalidArgumentException("Falta el identificador de usuario de la IA (openaiUserId).");
        }

        // Normalizar patente: mayúsculas y sin espacios
        $arguments['patente'] = strtoupper(str_replace(' ', '', $arguments['patente'] ?? ''));
        
        // Validar argumentos
        $validated = $this->validateVehicle($arguments);
        
        // Llamar al service
        $result = $this->vehicleService->identifyVehicle(
            patente: $validated['patente'] ,
            marca: $validated['marca'],
            modelo: $validated['modelo'],
            version: $validated['version'],
            year: $validated['anio'],
            combustible: $validated['combustible'],
            codigoPostal: $validated['codigo_postal'],
            openaiUserId: $openaiUserId, // Usamos el ID que extrajimos en handleToolCall
            threadId: $validated['thread_id']
        );
        
        return $result;
    }

    /**
     * Valida y sanea los argumentos del vehículo.
     * @param array $arguments
     * @return array Datos validados y limpios
     * @throws \InvalidArgumentException Si la validación falla
     */
    private function validateVehicle(array $arguments): array
    {
        $rules = [
            'patente' => [
                'required',
                'string',
                'regex:/^([A-Z]{3}\s?\d{3}|[A-Z]{2}\s?\d{3}\s?[A-Z]{2})$/i'
            ],
            'marca'         => 'required|string|max:100',
            'modelo'        => 'required|string|max:100',
            'version'       => 'required|string|max:100',
            'anio'          => 'required|integer|min:1900|max:' . (date('Y') + 1),
            'combustible'   => 'required|string|in:nafta,Nafta,diesel,Diesel,gnc,GNC,electrico,Electrico,hibrido,Hibrido',
            'codigo_postal' => 'required|string|max:10',
            'thread_id'     => 'required|string|max:100',
        ];

        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Error de validación en identifyVehicle: ' . $validator->errors()->first()
            );
        }

        return $validator->validated();
    }
    
    /**
     * Valida y sanea el payload de entrada.
     * @param array $payload
     * @return array Datos validados y limpios
     * @throws \InvalidArgumentException Si la validación falla
     */
    private function validateCustomer(array $payload): array
    {
        // Definimos reglas robustas
        $validator = Validator::make($payload, [
            'identifier_type'  => 'required|string|in:email,phone,wbid', // Ejemplo: restringir valores
            'identifier_value' => 'required|string',
            'thread_id'        => 'required|string',
            'ai_provider'      => 'nullable|string',
            'openai_user_id'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            // Lanzamos tu excepción preferida con el mensaje del primer error encontrado
            $this->logCustomer('Validación fallida en AgentToolAdapter', ['errors' => $validator->errors()->all(), 'payload' => $payload]);
            throw new InvalidArgumentException(
                'Error de validación en AgentToolAdapter: ' . $validator->errors()->first()
            );
        }

        // Retorna SOLO los campos definidos en las reglas (seguridad)
        return $validator->validated();
    }

    private function formatError(string $msg, string $code): array
    {
        return [
            'success'    => false,
            'error'      => $msg,
            'error_code' => $code,
        ];
    }
}