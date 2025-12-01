<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Vehicle;
use App\Repositories\VehicleRepository;
//use App\Repositories\ConversationRepository;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;

class VehicleIdentificationService
{
    use ConditionalLogger;
    //protected ConversationRepository $conversationRepository;

    public function __construct(
        private readonly VehicleRepository $vehicleRepo,
        //ConversationRepository $conversationRepository
    ) {
        
        //$this->conversationRepository = $conversationRepository;
    }
    
    /**
     * Busca un vehículo por patente o lo crea si no existe.
     * NO actualiza datos si el vehículo ya existe.
     */
    public function findOrCreate(Customer $customer, array $data): Vehicle
    {
        // 1. Pre-procesamiento de datos crudos
        $data['patente'] = strtoupper(str_replace(' ', '', $data['patente']));
        
        // Mapeamos 'anio' (del JSON tool) a 'year' (de la BD) si existe
        if (isset($data['anio'])) {
            $data['year'] = $data['anio'];
        }

        // 2. Limpieza y Filtrado (AQUÍ CORREGIMOS EL ERROR)
        // Usamos el método privado para filtrar basura y normalizar (ej: combustible)
        $cleanData = $this->filterVehicleData($data);

        // 3. Delegación con datos limpios
        return $this->vehicleRepo->findOrCreate($cleanData, $customer);
    }

    /**
     * Actualiza un vehículo existente aplicando política restrictiva pero confiada.
     * Al ser strict: true, sabemos que los datos vienen completos.
     * Actualizamos: Dueño, CP, Versión y Combustible.
     * Inmutables: Marca, Modelo, Año (Preservamos la integridad del "casco" del auto).
     * @param Vehicle  $vehicle El vehículo a actualizar
     * @param Customer $newOwner El nuevo dueño del vehículo
     * @param array  $data Datos validados y completos del vehículo
     * @return Vehicle El vehículo actualizado
     */
    public function updateVehicle(Vehicle $vehicle, Customer $newOwner, array $data): Vehicle
    {
        $updates = [
            'customer_id'   => $newOwner->id,      // Transferencia de propiedad
            'codigo_postal' => $data['codigo_postal'], // Actualización de zona de riesgo
            'version'       => $data['version'],       // Refinamiento de versión
        ];

        // Normalizamos combustible y lo actualizamos (ej: el usuario agregó GNC)
        if (isset($data['combustible'])) {
             $updates['combustible'] = strtolower($data['combustible']);
        }

        $this->vehicleRepo->update($vehicle, $updates);
        $vehicle->refresh();
        
        return $vehicle;
    }

    /**
     * Filtra y normaliza datos. Por las dudas si el payload no coincide 100%.
     */
    private function filterVehicleData(array $data): array
    {
        // Normalización centralizada
        if (isset($data['combustible'])) {
            $data['combustible'] = strtolower($data['combustible']);
        }
        
        // Si ya mapeamos anio -> year arriba, nos aseguramos de que year pase
        // y anio se quede afuera (gracias al array_flip de allowedFields).
        
        $allowedFields = [
            'customer_id', 'patente', 'marca', 'modelo', 'version', 
            'year', 'combustible', 'codigo_postal'
        ];
        
        return array_intersect_key($data, array_flip($allowedFields));
    }
}