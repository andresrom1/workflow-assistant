<?php
// app/Services/CustomerIdentificationService.php
namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;

class CustomerIdentificationService
{
    use ConditionalLogger;
     /**
      * Constructor
      */
    public function __construct(
        private readonly CustomerRepository $customerRepo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ConversationRepository $conversationRepo,
    ) {}

    /**
     * Identificar cliente por tipo y valor
     * @param string $type El tipo de identificador
     * @param string  $value El el valor del identificador
     * @param string  $threadId El treadId
     * @param Conversation $conversation
     * 
     * @return array{
     *   success:bool,
     *   customer_id:int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   dni:?string,
     *   is_new:bool,
     *   previous_conversations:Collection<int,array{thread_id:string,date:string,status:string,vehicle_count:int}>,
     *   vehicles:Collection<int,array{id:int,patente:string,marca:string,modelo:string,año:int,is_identified:bool}>,
     *   message:string
     * }
     */
    public function identify(string $type, string $value, string $threadId, $conversation): array
    {
        $this->logCustomer('Service: Iniciando identificación', [
            'type' => $type,
            'value' => $value,
            'thread_id' => $threadId,
        ]);

    
        $this->validateIdentifier($type, $value);

        // PASO 1: Buscar customer existente
        Log::info(__METHOD__ . __LINE__ . 'Buscando cliente existente', ['type' => $type, 'value' => $value]);
        
        $customer = $this->findCustomer($type, $value);

        Log::info(__METHOD__. __LINE__ . 'Resultado de búsqueda de cliente:', [$customer]);
        //$customer = $result['customer'];
        //$identifiedVehicle = $result['vehicle'];

        // PASO 2: Si NO encontró customer, buscar por thread (customer anónimo previo)
        if (!$customer) {
            Log::info(__METHOD__. __LINE__ . 'No se encontró cliente, buscando por thread para cliente anónimo', ['thread_id' => $threadId]);
            $customer = $this->findAnonymousCustomerByThread($threadId);
            
            if ($customer && $type !== 'patente') {
                // Es un customer anónimo que ahora da su identificador
                return $this->completeAnonymousCustomer($customer, $type, $value, $threadId);
            }
        }
        Log::info(__METHOD__. __LINE__ . 'Cliente después de buscar anónimo por thread:', ['customer' => $customer]);
        // PASO 3:  Si encontró customer, manejar como existente
        //          Si no encontró nada, crear nuevo (puede ser anónimo)

        if ($customer) {
            Log::info(__METHOD__. __LINE__ . ' Cliente existente encontrado', ['customer_id' => $customer->id]);
            $prepCustomer = $this->handleExistingCustomer($customer, null, $threadId, $conversation);
        } else {
            Log::info(__METHOD__. __LINE__ . ' No se encontró cliente, creando nuevo');
            $prepCustomer = $this->handleNewCustomer($type, $value, $threadId);
            $customer = $prepCustomer['customer'];
        }

        // 3. Vincular conversación con cliente (si aún no está vinculada)
        if (!$conversation->customer_id) {
            $this->conversationRepo->linkCustomer($conversation->id, $customer->id);
            $conversation->refresh();
        }

        // 4. Actualizar actividad
        $this->conversationRepo->updateActivity($threadId);

        return $prepCustomer;
    }
    
    /**
     * Buscar customer anónimo por thread_id
     * @param string $threadId El trhead ID
     * @return ?Customer
     */
    private function findAnonymousCustomerByThread(string $threadId): ?Customer
    {
        $conversation = $this->conversationRepo->findByThreadId($threadId);
        
        if ($conversation && $conversation->customer && $conversation->customer->is_anonymous) {
            return $conversation->customer;
        }
        
        return null;
    }

    /**
     * Completar customer anónimo con nuevo identificador
     * @param Customer $customer El customer anónimo
     * @param string $type El tipo de identificador
     * @param string $value El valor del identificador
     * @param string $threadId El thread ID
     * @return array
     */
    private function completeAnonymousCustomer(
        Customer $customer, 
        string $type, 
        string $value, 
        string $threadId): array 
        {
            Log::info('Completando customer anónimo', [
                'customer_id' => $customer->id,
                'type' => $type,
            ]);

            // Actualizar customer con el identificador
            $customer = $this->customerRepo->completeAnonymous($customer, $type, $value);

            // Obtener datos actualizados
            $conversations = $this->customerRepo->getConversations($customer);
            $vehicles = $this->customerRepo->getVehicles($customer);

            return [
                'success' => true,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'dni' => $customer->dni,
                'is_new' => false,
                'was_anonymous' => true,
                'is_anonymous' => false,
                'previous_conversations' => $conversations,
                'vehicles' => $vehicles,
                'message' => $customer->name
                    ? "Perfecto {$customer->name}, ya te he identificado completamente."
                    : 'Perfecto, ya te he identificado completamente.',
            ];
        }

    /**
     * Buscar cliente o vehículo según el tipo y valor
     * 
     * @return Customer|null
     */
    private function findCustomer(string $type, string $value): ?Customer
    {
        $this->logCustomer('Service: Buscando cliente', ['type' => $type, 'value' => $value]);
        
        // $customer = match ($type) {
        //     'patente' => [
        //         'vehicle' => $vehicle = $this->vehicleRepo->findByPatente($value),
        //         'customer' => $vehicle?->customer ?? $this->handleOrphanVehicle($vehicle),
        //     ],
        //     'dni' => [
        //         'vehicle' => null,
        //         'customer' => $this->customerRepo->findByDni($value),
        //     ],
        //     'email' => [
        //         'vehicle' => null,
        //         'customer' => $this->customerRepo->findByEmail($value),
        //     ],
        //     'phone' => [
        //         'vehicle' => null,
        //         'customer' => $this->customerRepo->findByPhone($value),
        //     ],
        // };

        $customer = $this->customerRepo->findByType($type, $value);

        $this->logCustomer('Cliente encontrado por tipo', ['customer' => $customer]);
        return $customer;
    }

    private function handleExistingCustomer(Customer $customer, ?Vehicle $identifiedVehicle, string $threadId, Conversation $conversation): array
    {
        Log::info('Cliente existente', ['customer_id' => $customer->id]);

        $conversations = $this->customerRepo->getConversations($customer);
        $vehicles = $this->customerRepo->getVehicles($customer, $identifiedVehicle);

        //$conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id); //Ya esta creada
        if ($identifiedVehicle) {
            $this->conversationRepo->attachVehicle($conversation, $identifiedVehicle->id, true);
        }

        return [
            'success'                 => true,
            'customer_id'             => $customer->id,
            'name'                    => $customer->name,
            'email'                   => $customer->email,
            'phone'                   => $customer->phone,
            'dni'                     => $customer->dni,
            'is_new'                  => false,
            'is_anonymous'            => $customer->is_anonymous,
            'previous_conversations'  => $conversations,
            'vehicles'                => $vehicles,
            'message'                 => $customer->name
                ? "Bienvenido de vuelta, {$customer->name}!"
                : 'Cliente identificado correctamente',
        ];
    }

    private function handleNewCustomer(string $type, string $value, string $threadId): array
    {
        Log::info('Nuevo cliente', [$type => $value]);

        // Crear customer según el tipo de identificador
        $customerData = match($type) {
            'email' => ['email' => $value],
            'dni' => ['dni' => $value],
            'phone' => ['phone' => $value],
            'patente' => [
                'metadata' => json_encode([
                    'initial_identifier' => 'patente',
                    'initial_value' => strtoupper($value),
                ])
            ], // Sin datos de contacto → será anónimo
        };
        Log::info(__METHOD__ . __LINE__ . ' Datos para nuevo cliente:', $customerData);

        $customer = $this->customerRepo->create($customerData);

        Log::info(__METHOD__ . __LINE__ . ' Cliente creado', ['customer' => $customer]);
        
        // Si es patente, crear vehicle
        $vehicle = null;
        if ($type === 'patente') {
            $vehicle = $this->vehicleRepo->create([
                'customer_id' => $customer->id,
                'patente'     => strtoupper($value),
            ]);
        }

        // Crear conversation
        $conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id);
        if ($vehicle) {
            $this->conversationRepo->attachVehicle($conv, $vehicle->id, true);
        }

        return [
            'success'                => true,
            'customer'               => $customer,
            'customer_id'            => $customer->id,
            'name'                   => null,
            'email'                  => $type === 'email' ? $value : null,
            'phone'                  => null,
            'dni'                    => $type === 'dni' ? $value : null,
            'is_new'                 => true,
            'is_anonymous'           => $customer->is_anonymous,
            'previous_conversations' => collect([]),
            'vehicles'               => $vehicle
                ? collect([[
                    'id'            => $vehicle->id,
                    'patente'       => $vehicle->patente,
                    'marca'         => $vehicle->marca ?? '',
                    'modelo'        => $vehicle->modelo ?? '',
                    'año'           => $vehicle->año ?? 0,
                    'is_identified' => true,
                ]])
                : collect([]),
            'message' => '¡Bienvenido! Eres un cliente nuevo',
        ];
        
    }

    /**
     * Validar el identificador según su tipo
     * 
     * @throws \InvalidArgumentException Si el identificador es inválido
     */
    private function validateIdentifier(string $type, string $value): void
    {
        $ok = match ($type) {
            'dni'     => preg_match('/^\d{7,8}$/', $value),
            'email'   => filter_var($value, FILTER_VALIDATE_EMAIL),
            'phone' => $this->validatePhone($value),
            'patente' => $this,
        };

        if (!$ok) {
            throw new \InvalidArgumentException(
                match($type) {
                    'dni'     => 'DNI inválido',
                    'email'   => 'Email inválido',
                    'patente' => 'Patente inválida (ej: ABC123)',
                }
            );
        }
    }

    private function handleOrphanVehicle(?Vehicle $vehicle): ?Customer
    {
        Log::info(__METHOD__ . __LINE__ . 'Vehículo sin cliente', ['vehicle_id' => $vehicle?->id ?? null, 'customer_id' => $vehicle?->customer_id ?? null]);
        if (!$vehicle) return null;
        
        if (!$vehicle->customer_id) {
            Log::warning('Vehículo sin cliente', ['vehicle_id' => $vehicle->id]);
            // Opción: asociar a un cliente "anónimo" o lanzar excepción
        }
        
        return $vehicle->customer;
    }

    private function validatePatente(string $value): bool
    {
        //'patente' => preg_match('/^[A-Z]{2,3}\d{3}[A-Z]{0,2}$/i', $value),
        $patterns = [
            '/^[A-Z]{3}\d{3}$/i',        // ABC123 (viejo)
            '/^[A-Z]{2}\d{3}[A-Z]{2}$/i', // AB123CD (Mercosur)
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return false;
    }
    private function validatePhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        return preg_match('/^(\+?549?)?\d{10,13}$/', $cleaned) === 1;
    }
}