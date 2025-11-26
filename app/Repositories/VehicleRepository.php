<?php
// app/Repositories/VehicleRepository.php

namespace App\Repositories;

use App\Models\Vehicle;
use Log;

class VehicleRepository
{
    
    public function create(array $data): Vehicle
    {
        return Vehicle::create($data);
    }
    
    public function update(Vehicle $vehicle, array $data): bool
    {
        return $vehicle->update($data);
    }
    
    public function findById(int $id): ?Vehicle
    {
        return Vehicle::find($id);
    }

    public function findByPatente(string $patente): ?Vehicle
    {
        Log::info(__METHOD__ . __LINE__ . ' Buscando cliente', ['patente' => $patente]);
        return Vehicle::where('patente', strtoupper($patente))->first();
    }

    public function findOrCreate(array $specs): Vehicle
    {
        return Vehicle::firstOrCreate(
            [
                'patente' => $specs['patente'],
                
            ],
            [
                'marca' => $specs['marca'],
                'modelo' => $specs['modelo'],
                'version' => $specs['version'],
                'year' => $specs['year'],
                'combustible' => $specs['combustible'] ?? null,
                'codigo_postal' => $specs['codigo_postal'] ?? null,
                'customer_id' => $specs['customer_id'] ?? null,
                'thread_id' => $specs['thread_id'] ?? null,
            ]
        );
    }

    public function linkToCustomer(int $vehicleId, int $customerId): void
    {
        Vehicle::where('id', $vehicleId)->update([
            'customer_id' => $customerId,
        ]);
    }

    public function getAllWithRelations(
        array $relations = [],
        string $search = '',
        int $perPage = 15
    ) {
        $query = Vehicle::query()
            ->with($relations)
            ->orderBy('created_at', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('patente', 'like', "%{$search}%")
                  ->orWhere('marca', 'like', "%{$search}%")
                  ->orWhere('mdelo', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function getIncompleteVehicles()
    {
        return Vehicle::where('is_complete', false)->get();
    }
}