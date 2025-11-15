<?php
// app/Services/CustomerIdentificationService.php
namespace App\Services;

use App\Models\Customer;
use App\Models\Vehicle;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CustomerIdentificationService
{
    public function __construct(
        private readonly CustomerRepository $customerRepo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ConversationRepository $conversationRepo,
    ) {}

    /**
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
    public function identify(string $type, string $value, string $threadId): array
    {
        $this->validateIdentifier($type, $value);

        $result = $this->findCustomer($type, $value);
        $customer = $result['customer'];
        $identifiedVehicle = $result['vehicle'];

        if ($customer) {
            return $this->handleExistingCustomer($customer, $identifiedVehicle, $threadId);
        }

        return $this->handleNewCustomer($type, $value, $threadId);
    }
    
    /**
     * Buscar cliente o vehículo según el tipo y valor
     * 
     * @return array{customer:?Customer, vehicle:?Vehicle}
     */
    private function findCustomer(string $type, string $value): array
    {
        return match ($type) {
            'patente' => [
                'vehicle' => $vehicle = $this->vehicleRepo->findByPatente($value),
                'customer' => $vehicle?->customer,
            ],
            'dni' => [
                'vehicle' => null,
                'customer' => $this->customerRepo->findByDni($value),
            ],
            'email' => [
                'vehicle' => null,
                'customer' => $this->customerRepo->findByEmail($value),
            ],
        };
    }

    private function handleExistingCustomer(Customer $customer, ?Vehicle $identifiedVehicle, string $threadId): array
    {
        Log::info('Cliente existente', ['customer_id' => $customer->id]);

        $conversations = $this->customerRepo->getConversations($customer);
        $vehicles = $this->customerRepo->getVehicles($customer, $identifiedVehicle);

        $conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id);
        if ($identifiedVehicle) {
            $this->conversationRepo->attachVehicle($conv, $identifiedVehicle->id, true);
        }

        return [
            'success'                 => true,
            'customer_id'             => $customer->id,
            'name'                    => $customer->name,
            'email'                   => $customer->email,
            'phone'                   => $customer->phone,
            'dni'                     => $customer->dni,
            'is_new'                  => false,
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

        $customer = $this->customerRepo->create(
            $type === 'email' ? ['email' => $value] :
            ($type === 'dni'  ? ['dni'   => $value] : [])
        );

        $vehicle = null;
        if ($type === 'patente') {
            $vehicle = $this->vehicleRepo->create([
                'customer_id' => $customer->id,
                'patente'     => strtoupper($value),
            ]);
        }

        $conv = $this->conversationRepo->createOrUpdate($threadId, $customer->id);
        if ($vehicle) {
            $this->conversationRepo->attachVehicle($conv, $vehicle->id, true);
        }

        return [
            'success'                => true,
            'customer_id'            => $customer->id,
            'name'                   => null,
            'email'                  => $type === 'email' ? $value : null,
            'phone'                  => null,
            'dni'                    => $type === 'dni' ? $value : null,
            'is_new'                 => true,
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

    private function validateIdentifier(string $type, string $value): void
    {
        $ok = match ($type) {
            'dni'     => preg_match('/^\d{7,8}$/', $value),
            'email'   => filter_var($value, FILTER_VALIDATE_EMAIL),
            'patente' => preg_match('/^[A-Z]{2,3}\d{3}[A-Z]{0,2}$/i', $value),
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
}