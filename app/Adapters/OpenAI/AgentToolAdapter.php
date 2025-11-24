<?php
// app/Adapters/OpenAI/AgentToolAdapter.php

namespace App\Adapters\OpenAI;

use App\Services\CustomerIdentificationService;
use App\Services\VehicleIdentificationService;
use Illuminate\Support\Facades\Log;

class AgentToolAdapter
{
    public function __construct(
        private readonly CustomerIdentificationService $customerService,
        private readonly VehicleIdentificationService $vehicleService,
    ) {}

    public function identifyCustomer(array $payload): array
    {
        Log::info('Adapter: identifyCustomer llamado', $payload);
        try {

            // Validación rápida (misma que antes)
            $type  = $payload['identifier_type']  ?? throw new \InvalidArgumentException('Falta identifier_type');
            $value = $payload['identifier_value'] ?? throw new \InvalidArgumentException('Falta identifier_value');
            $threadId = $payload['thread_id']    ?? throw new \InvalidArgumentException('Falta thread_id');

            // Llamar al service (ahora devuelve array)
            $result = $this->customerService->identify($type, $value, $threadId);
            Log::alert(__METHOD__ . 'Resultado de identificación:', $result);
            
            // El array ya viene en formato “OpenAI-friendly”
            return array_merge(['success' => true], $result);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Validación', ['error' => $e->getMessage()]);
            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Exception $e) {
            Log::error('Server', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->formatError('Error interno', 'server_error');
        }
    }

    public function identifyVehicle(array $arguments, string $openaiUserId): array
    {
        try {
            
            // Normalizar patente: mayúsculas y sin espacios
            $arguments['patente'] = strtoupper(str_replace(' ', '', $arguments['patente']));
            
            // Validar argumentos
            $validated = $this->validateArguments($arguments);
            
            // Llamar al service
            $result = $this->vehicleService->identifyVehicle(
                patente: $validated['patente'] ,
                marca: $validated['marca'],
                modelo: $validated['modelo'],
                version: $validated['version'],
                year: $validated['anio'],
                combustible: $validated['combustible'],
                codigoPostal: $validated['codigo_postal'],
                openaiUserId: $openaiUserId
            );
            
            return $result;
        } catch (\Exception $e) {
            Log::error('VehicleIdentificationAdapter error', [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'adapter_error',
            ];
        }
    }

    protected function validateArguments(array $arguments): array
    {
        $rules = [
            'patente' => 'required|regex:/^([A-Z]{3}\s?\d{3}|[A-Z]{2}\s?\d{3}\s?[A-Z]{2})$/i',
            'marca' => 'required|string|max:100',
            'modelo' => 'required|string|max:100',
            'version' => 'required|string|max:100',
            'anio' => 'required|integer|min:1900|max:' . (date('Y') + 1),
            'combustible' => 'required|string|in:Nafta,Diesel,GNC,Eléctrico,Híbrido',
            'codigo_postal' => 'required|string|max:10',
        ];

        $validator = validator($arguments, $rules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Validation failed: ' . $validator->errors()->first()
            );
        }

        return $validator->validated();
    }

    private function formatError(string $msg, string $code): array
    {
        return [
            'success'    => false,
            'error'      => $msg,
            'error_code' => $code,
        ];
    }
}