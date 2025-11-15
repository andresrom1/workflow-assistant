<?php
// app/Repositories/CustomerRepository.php

namespace App\Repositories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

class CustomerRepository
{
    public function findByDni(string $dni): ?Customer
    {
        return Customer::where('dni', $dni)->first();
    }

    public function findByEmail(string $email): ?Customer
    {
        return Customer::where('email', $email)->first();
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): bool
    {
        return $customer->update($data);
    }

    /**
     * Devuelve las últimas conversaciones del cliente como arrays.
     * 
     * @return \Illuminate\Support\Collection<int, array{thread_id: string, date: string, status: string, vehicle_count: int}>
     */
    public function getConversations(Customer $customer, int $limit = 5): Collection
    {
        return $customer->conversations()
            ->where('status', '!=', 'anonymous')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($conv) => [
                'thread_id' => $conv->thread_id,
                'date' => $conv->created_at->format('Y-m-d'),
                'status' => $conv->status,
                'vehicle_count' => $conv->vehicles()->count(),
            ]);
    }
    
    /**
     * Devuelve los vehículos del cliente como arrays.
     * 
     * @param Customer $customer
     * @param Vehicle|null $identifiedVehicle
     * 
     * @return Collection<int, array{id:int,patente:string,marca:string,modelo:string,año:int,is_identified:bool}>
     */
    public function getVehicles(Customer $customer,  ?Vehicle $identifiedVehicle = null): Collection
    {
        return $customer->vehicles()
            ->get()
            ->map(fn($vehicle) => [
            'id' => $vehicle->id,
            'patente' => $vehicle->patente,
            'marca' => $vehicle->marca,
            'modelo' => $vehicle->modelo,
            'año' => $vehicle->año,
            'is_identified' => $identifiedVehicle && $identifiedVehicle->id === $vehicle->id,
            ]);
    }
}