<?php

// app/Services/CustomerIdentificationService.php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
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
        private readonly CustomerMergeService $merge,
        private readonly CustomerConsolidationService $consolidation,
    ) {}

    /**
     * Identificar cliente por tipo y valor.
     * Busca al cliente y si no existe, lo crea.
     *
     * @return Customer (Nunca null, siempre devuelve una instancia válida)
     */
    public function findOrCreate(string $type, string $value): Customer
    {
        $this->logCustomer('Service: Iniciando identificación', ['type' => $type, 'value' => $value]);

        $this->validateIdentifier($type, $value);

        // PASO 1: Buscar customer existente
        $customer = $this->findCustomer($type, $value);

        if ($customer instanceof Customer) {
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
     * Resuelve la identidad del tomador para una conversación, aplicando el árbol
     * create / enrich / merge (ver docs/v2/12 §5.3). Es **agnóstico de canal**: recibe el
     * customer ya linkeado (`$linked`, o null) y devuelve la fila canónica resultante; el
     * llamador (adapter) se encarga de linkear la conversación al resultado.
     *
     * - Sin `$linked`: busca la fila dueña del identificador, o la crea.
     * - Con `$linked` y el identificador pertenece a OTRA fila → fusiona esa fila en `$linked`.
     * - Con `$linked` y NADIE posee el identificador → enriquece `$linked` (no crea una fila nueva).
     * - Con `$linked` y el identificador ya es de `$linked` → no-op.
     */
    public function resolveForConversation(string $type, string $value, ?Customer $linked): Customer
    {
        $this->validateIdentifier($type, $value);

        $existing = $this->findCustomer($type, $value);

        if (! $linked instanceof Customer) {
            return $existing ?? $this->createCustomer($type, $value);
        }

        if ($existing instanceof Customer && $existing->id !== $linked->id) {
            // El identificador ya pertenece a otra fila → fusionar (survivor = fila linkeada).
            return $this->merge->merge($linked, $existing);
        }

        if (! $existing instanceof Customer) {
            // Nadie posee el identificador → escribirlo en la fila linkeada (no crear otra).
            $this->consolidation->apply($linked, [$type => $value], 'chat');

            return $linked->refresh();
        }

        return $linked; // $existing es la fila ya linkeada
    }

    /**
     * Buscar cliente según el tipo y valor. Normaliza email/dni igual que el merge para que
     * el match sea consistente (`findByPhone` ya normaliza internamente).
     */
    private function findCustomer(string $type, string $value): ?Customer
    {
        $this->logCustomer('Service: Buscando cliente', ['type' => $type, 'value' => $value]);

        $customer = match ($type) {
            'phone' => $this->customerRepo->findByPhone($value),
            'email' => $this->customerRepo->findByEmail(mb_strtolower(trim($value))),
            'dni' => $this->customerRepo->findByDni(trim($value)),
            default => null,
        };

        $this->logCustomer('Cliente encontrado por tipo', ['customer' => $customer]);

        return $customer;
    }

    /**
     * Crear un nuevo cliente
     *
     * @param  string  $type  Tipo de identificador
     * @param  string  $value  Valor del identificador
     */
    private function createCustomer(string $type, string $value): Customer
    {
        // Crear customer según el tipo de identificador
        $customerData = match ($type) {
            'email' => ['email' => $value],
            'phone' => ['phone' => $value],
            'dni' => ['dni' => $value],
            default => throw new \InvalidArgumentException("Tipo de identificador no soportado: {$type}"),
        };
        $this->logCustomer('Service: Datos para nuevo cliente', $customerData);

        $customer = $this->customerRepo->create($customerData);

        $this->logCustomer('Service: Cliente creado', ['customer' => $customer]);

        // Crear conversation esto hay que sacarlo al nivel del adapter
        // $conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id);

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
            'dni' => preg_match('/^\d{7,8}$/', $value) === 1,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => $this->validatePhone($value),
            default => throw new \InvalidArgumentException("Tipo de identificador no soportado: {$type}"),
        };

        if (! $ok) {
            throw new \InvalidArgumentException(match ($type) {
                'dni' => 'DNI inválido',
                'email' => 'Email inválido',
                'phone' => 'Teléfono inválido',
            });
        }
    }

    private function validatePhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        return preg_match('/^(\+?549?)?\d{10,13}$/', (string) $cleaned) === 1;
    }
}
