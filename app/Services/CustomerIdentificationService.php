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
     * Identificar cliente por tipo y valor.
     * Busca al cliente y si no existe, lo crea.
     * @param string $type 
     * @param string $value 
     * @return Customer (Nunca null, siempre devuelve una instancia válida)
     */
    public function findOrCreate(string $type, string $value): Customer
    {
        $this->logCustomer('Service: Iniciando identificación', ['type' => $type, 'value' => $value]);
    
        $this->validateIdentifier($type, $value);

        // PASO 1: Buscar customer existente
        $customer = $this->findCustomer($type, $value);

        if ($customer) {
            $this->logCustomer('Cliente encontrado', ['id' => $customer->id]);
            return $customer;
        }

        // PASO 2: Si no existe, CREARLO (Restauramos esta lógica)
        $this->logCustomer('Cliente no encontrado, creando nuevo', ['type' => $type]);

        // if ($customer) {
        //     $this->logCustomer('Cliente existente encontrado', ['customer_id' => $customer->id]);
        //     $prepCustomer = $this->handleExistingCustomer($customer, null, $external_conversation_id, $conversation);
        // } else {
        //     $this->logCustomer('No se encontró cliente existente, creando nuevo', ['type' => $type, 'value' => $value]);
        //     $prepCustomer = $this->createCustomer($type, $value);
        //     $customer = $prepCustomer['customer'];
        // }
        
        return $this->createCustomer($type, $value);
    

        // // PASO 2: Si NO encontró customer, buscar por thread (customer anónimo previo)
        // if (!$customer) {
        //     $this->logCustomer('No se encontró cliente, buscando por thread para cliente anónimo', ['thread_id' => $external_conversation_id]);
        //     $customer = $this->findAnonymousCustomerByThread($external_conversation_id);
            
        //     if ($customer && $type !== 'patente') {
        //         // Es un customer anónimo que ahora da su identificador
        //         return $this->completeAnonymousCustomer($customer, $type, $value, $external_conversation_id);
        //     }
        // }

        // $this->logCustomer('Cliente después de buscar anonimo por thread', ['customer' => $customer]);
        // // PASO 3:  Si encontró customer, manejar como existente
        // //          Si no encontró nada, crear nuevo (puede ser anónimo)

        // if ($customer) {
        //     $this->logCustomer('Cliente existente encontrado', ['customer_id' => $customer->id]);
        //     $prepCustomer = $this->handleExistingCustomer($customer, null, $external_conversation_id, $conversation);
        // } else {
        //     $this->logCustomer('No se encontró cliente existente, creando nuevo', ['type' => $type, 'value' => $value]);
        //     $prepCustomer = $this->createCustomer($type, $value);
        //     $customer = $prepCustomer['customer'];
        // }

        // // 3. Vincular conversación con cliente (si aún no está vinculada)
        // if (!$conversation->customer_id) {
        //     $this->conversationRepo->linkCustomer($conversation->id, $customer->id);
        //     $conversation->refresh();
        // }

        // // 4. Actualizar actividad
        // $this->conversationRepo->updateActivity($external_conversation_id);

        // return $prepCustomer;
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
     * Buscar cliente según el tipo y valor
     * 
     * @return Customer|null
     */
    private function findCustomer(string $type, string $value): ?Customer
    {
        $this->logCustomer('Service: Buscando cliente', ['type' => $type, 'value' => $value]);
        
        $customer = $this->customerRepo->findByType($type, $value);

        $this->logCustomer('Cliente encontrado por tipo', ['customer' => $customer]);
        return $customer;
    }

    /**
     * Crear un nuevo cliente
     * 
     * @param string $type Tipo de identificador
     * @param string $value Valor del identificador
     * @return Customer
     */
    private function createCustomer(string $type, string $value): Customer
    {
        // Crear customer según el tipo de identificador
        $customerData = match($type) {
            'email' => ['email' => $value],
            'phone' => ['phone' => $value],        
        };
        $this->logCustomer('Service: Datos para nuevo cliente', $customerData);

        $customer = $this->customerRepo->create($customerData);
        
        $this->logCustomer('Service: Cliente creado', ['customer' => $customer]);
        

        // Crear conversation esto hay que sacarlo al nivel del adapter
        //$conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id);

        return $customer;
        
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