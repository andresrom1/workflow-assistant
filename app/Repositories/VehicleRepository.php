<?php
// app/Repositories/VehicleRepository.php

namespace App\Repositories;

use App\Models\Vehicle;

class VehicleRepository
{
    public function findByPatente(string $patente): ?Vehicle
    {
        return Vehicle::where('patente', strtoupper($patente))->first();
    }

    public function create(array $data): Vehicle
    {
        return Vehicle::create($data);
    }

    public function update(Vehicle $vehicle, array $data): bool
    {
        return $vehicle->update($data);
    }
}