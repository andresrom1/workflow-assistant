<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Log;

class RiskSnapshotRepository
{
    use ConditionalLogger;
    /**
     * Crea un snapshot inmutable basado en las entidades vivas.
     * @param Customer $customer
     * @param Vehicle $vehicle
     * @return RiskSnapshot
     */
    public function createFromEntities(Customer $customer, Vehicle $vehicle): RiskSnapshot
    {
        $this->logRsikSnapshot("Creating snapshot for Vehicle ID: {$vehicle->id}");

        return RiskSnapshot::create([
            'customer_id' => $customer->id,
            'vehicle_id'  => $vehicle->id,

            // Copia textual del Vehículo
            'marca'         => $vehicle->marca,
            'modelo'        => $vehicle->modelo,
            'version'       => $vehicle->version,
            'year'          => (int) $vehicle->year,
            'combustible'   => (string) $vehicle->combustible,
            'uso'           => $vehicle->uso,
            'codigo_postal' => $vehicle->codigo_postal,

            // Copia textual del Cliente
            'dni'            => $customer->dni,
            'edad_conductor' => $customer->birth_date ?? null, 
        ]);
    }
}