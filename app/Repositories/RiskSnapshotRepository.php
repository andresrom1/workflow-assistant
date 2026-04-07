<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Traits\ConditionalLogger;

class RiskSnapshotRepository
{
    use ConditionalLogger;

    /**
     * Crea un snapshot inmutable basado en las entidades vivas.
     */
    public function createFromEntities(Customer $customer, Vehicle $vehicle, ?string $coveragePreference = null): RiskSnapshot
    {
        $this->logRsikSnapshot("Creating snapshot for Vehicle ID: {$vehicle->id}");

        return RiskSnapshot::create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,

            // Copia textual del Vehículo
            'marca' => $vehicle->marca,
            'modelo' => $vehicle->modelo,
            'version' => $vehicle->version,
            'year' => (int) $vehicle->year,
            'combustible' => (string) $vehicle->combustible,
            'uso' => $vehicle->uso,
            'codigo_postal' => $vehicle->codigo_postal,

            // Copia textual del Cliente
            'dni' => $customer->dni,
            'edad_conductor' => $customer->birth_date ?? null,
            'coverage_preference' => $coveragePreference,
        ]);
    }

    /**
     * Actualiza la preferencia de cobertura en un snapshot existente.
     */
    public function updateCoveragePreference(RiskSnapshot $snapshot, string $preference): void
    {
        $snapshot->update(['coverage_preference' => $preference]);
        $this->logRsikSnapshot("Updated coverage preference for Snapshot ID: {$snapshot->id}");
    }
}
