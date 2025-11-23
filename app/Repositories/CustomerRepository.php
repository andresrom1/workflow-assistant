<?php
// app/Repositories/CustomerRepository.php

namespace App\Repositories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CustomerRepository
{
    public function findByDni(string $dni): ?Customer
    {
        Log::info( __METHOD__ . ' Buscando customer por DNI', ['dni' => $dni]);
        return Customer::where('dni', $dni)->first();
    }

    public function findByEmail(string $email): ?Customer
    {
        return Customer::where('email', $email)->first();
    }
    public function findByPhone(string $phone): ?Customer
    {
        $normalized = $this->normalizePhone($phone);
        return Customer::where('phone', $normalized)->first();
    }

/**
     * Crear customer (puede ser anónimo)
     */
    public function create(array $data): Customer
    {
        // Normalizar datos
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }
        
        // Determinar si es anónimo
        $isAnonymous = !isset($data['dni']) 
                    && !isset($data['email']) 
                    && !isset($data['phone']);
        
        $data['is_anonymous'] = $isAnonymous;
        
        if (!$isAnonymous) {
            $data['completed_at'] = now();
        }
        
        return Customer::create($data);
    }

    /**
     * Actualizar customer (por ejemplo, completar anónimo)
     * @param Customer  $customer
     * @param array $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        // Normalizar datos
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }
        
        $customer->update($data);
        
        // Si ahora tiene datos de contacto, marcar como completo
        if ($customer->hasContactInfo() && $customer->is_anonymous) { 
            //analizar si $customer->is_anonymous es condicion requerida para marcar como completo
            // En principio, si tiene datos de contacto, se puede marcar como completo 
            $customer->markAsComplete();
        }
        
        return $customer->fresh();
    }

    /**
     * Completar customer anónimo con nuevo identificador
     */
    public function completeAnonymous(Customer $customer, string $type, string $value): Customer
    {
        if (!$customer->is_anonymous) {
            throw new \Exception('Customer no es anónimo');
        }

        $updateData = match($type) {
            'dni' => ['dni' => $value],
            'email' => ['email' => $value],
            'phone' => ['phone' => $value],
        };

        return $this->update($customer, $updateData);
    }

    /**
     * Normalizar teléfono argentino
     */
    private function normalizePhone(string $phone): string
    {
        // Quitar todo excepto números y +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Si empieza con 0, quitarlo
        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }
        
        // Si no tiene código de país, agregar +549 (Argentina celular)
        if (!str_starts_with($phone, '+')) {
            if (strlen($phone) === 10) { // 3512345678
                $phone = '+549' . $phone;
            } elseif (strlen($phone) === 13 && str_starts_with($phone, '549')) {
                $phone = '+' . $phone;
            }
        }
        
        return $phone;
    }

    /**
     * Devuelve las últimas conversaciones del cliente como arrays.
     * 
     * @return \Illuminate\Support\Collection<int, array{thread_id: string, date: string, status: string, vehicle_count: int}>
     */
    public function getConversations(Customer $customer, int $limit = 5): Collection
    {
        return $customer->conversations()
            ->with('vehicles')
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

    /**
     * Obtener todos los customers con relaciones, búsqueda y paginación
     * @param array  $relations
     * @param string $search
     * @param int    $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */ 
    public function getAllWithRelations(array $relations = [], string $search = '', int $perPage = 15)
    {
        $query = Customer::with($relations);

        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhere('phone', 'like', "%$search%")
                  ->orWhere('dni', 'like', "%$search%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Summary of findWithRelations
     * @param int $id
     * @param array $relations
     * @return Customer
     */
    public function findWithRelations(int $id, array $relations = []): ?Customer
    {
        return Customer::with($relations)->find($id);
    }
    
    /**
     * Get customers count
     */
    public function count(): int
    {
        return Customer::count();
    }

    /**
     * Get recently created customers
     */
    public function getRecent(int $limit = 10): Collection
    {
        return Customer::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}