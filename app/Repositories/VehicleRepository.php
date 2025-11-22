<?php
// app/Repositories/VehicleRepository.php

namespace App\Repositories;

use App\Models\Vehicle;
use Log;

class VehicleRepository
{
    public function findByPatente(string $patente): ?Vehicle
    {
        Log::info(__METHOD__ . __LINE__ . ' Buscando cliente', ['patente' => $patente]);
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