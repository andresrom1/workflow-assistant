<?php

namespace App\Adapters\OpenAI;

use App\Contracts\AIProviderAdapterInterface;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Repositories\ConversationRepository;
use App\Services\CustomerIdentificationService;
use App\Services\VehicleIdentificationService;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use App\Models\Conversation;
use Illuminate\Http\Request;

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
    public function handleToolCall(array $payload, string $toolName): array
    {
        // 1- Validar que el request tiene todos los datos necesarios
        Validator::make($payload, [
            'thread_id'      => 'required|string',
            'openai_user_id' => 'required|string',
        ])->validate();

        // Transformaciones necesarias para continuar agnostico
        $data = $payload;
        $data['external_conversation_id'] = $data['thread_id'];
        $data['external_user_id'] = $data['openai_user_id'];
        unset($data['thread_id']);
        unset($data['openai_user_id']);
        unset($data['ai_provider']);

        $conversation = $this->conversationRepo
            ->findOrCreateByExternalId($data['external_conversation_id']);

        $this->logAdapter(
            "HTTP Tool Request recibido: {$toolName}", 
            ['payload' => $payload, 'conversation_id' => $conversation->id]);

        try {
            return match ($toolName) {
                'identify_customer' => $this->identifyCustomer($data, $conversation),
                'identify_vehicle' => $this->identifyVehicle($data, $conversation), // Aun no implementada
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
     * Identifica cliente y lo vincula a la conversación actual.
     * @param array $payload
     * @param Conversation  $conversation
     * Retorna ARRAY para la IA (No el objeto Customer).
     */
    protected function identifyCustomer(array $payload, Conversation $conversation): array
    {
        $this->logCustomer('Adapter: Iniciando identificación de cliente', $payload);
        
        // 1. Validamos y Obtenemos el Cliente (Si falla, deja que la excepción suba)
        // No uses try/catch aquí si vas a silenciar el error. 
        // Deja que handleToolCall capture la excepción y devuelva el error formateado.
        
        $data = $this->validateCustomer($payload); // Usamos el método validador privado

        $customer = $this->customerService->findOrCreate(
            $data['identifier_type'], 
            $data['identifier_value']
        );

        $this->logCustomer('Adapter: Cliente obtenido del servicio', ['id' => $customer->id]);

        // 2. ORQUESTACIÓN Y VINCULACIÓN
        // Lógica: Siempre vinculamos el cliente actual a la conversación.
        // Esto cubre:
        // A. Conversación nueva (Anónimo -> Identificado)
        // B. Corrección de error (Dueño A -> Dueño B)
        
        $currentOwnerId = $conversation->customer_id;

        // Solo auditamos si hubo un cambio real de dueño
        if ($currentOwnerId && $currentOwnerId !== $customer->id) {
            $this->logCustomer('Cambio de titularidad en conversación', [
                'conversation_id' => $conversation->id,
                'from' => $currentOwnerId,
                'to' => $customer->id]);
        }

        // Ejecutamos la vinculación (Si ya es el mismo, el update es barato o el repo lo maneja)
        $this->conversationRepo->linkCustomer($conversation->id, $customer->id);
        
        // 3. CONSTRUCCIÓN DE MEMORIA (Opcional, si usas la lógica de vehículos/quotes)
        // ... (Aquí iría la lógica de buildCustomerHistoryContext) ...

        // 4. RETORNO BLINDADO (Array para la IA)
        return [
            'success' => true,
            'tool_output' => "Cliente identificado correctamente",
        ];
    }

    /**
     * @param array $data
     * @param Conversation  $conversation
     * @return array
     */
    protected function identifyVehicle(array $data, Conversation $conversation): array
    {
        // 1. Cargar el Customer (Garantizado por el flujo)
        // Usamos la relación para cargar el modelo Customer.
        /** @var Customer $customer */
        $customer = $conversation->customer;
        
        if (!$customer) {
            // Defensa en profundidad: aunque el flujo lo garantice, el código se protege.
            return $this->formatError("No se ha identificado un cliente para asignar el vehículo.", "missing_customer");
        } 
        
        $this->logAdapter('Adapter: Iniciando identificación de vehículo', [
            'data' => $data,
        ]);
        // 2. Validar Payload (Patente, Marca, etc.)
        $data = $this->validateVehicle($data);

        // 3. Servicio PURO (Find or Create, con lógica de transferencia de propiedad)
        /** @var Vehicle $vehicle */
        $vehicle = $this->vehicleService->findOrCreate($customer, $data);

        // 4. Lógica de Actualización / Transferencia (Decisión del Orquestador)
        // Si el vehículo ya existía (no es nuevo), aprovechamos para actualizar datos permitidos
        // (como CP o Versión) y transferir la propiedad si el dueño era otro.
        if ($vehicle->wasRecentlyCreated === false) {
             // El servicio updateVehicle maneja la lógica restrictiva de qué campos tocar
             $this->vehicleService->updateVehicle($vehicle, $customer, $data);
             $this->logAdapter('Adapter: Datos de vehículo actualizados/transferidos.', ['patente' => $vehicle->patente]);
        }

        // 5. Salida Blindada
        return [
            'success' => true,
            'tool_output' => "Vehículo registrado correctamente."
        ];
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
            'year'          => 'required|integer|min:1900|max:' . (date('Y') + 1),
            'combustible'   => 'required|string|in:nafta,Nafta,diesel,Diesel,gnc,GNC,electrico,Electrico,hibrido,Hibrido',
            'codigo_postal' => 'required|string|max:10',
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
            'external_conversation_id' => 'required|string',
            'ai_provider'      => 'nullable|string',
            'external_user_id'   => 'nullable|string',
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