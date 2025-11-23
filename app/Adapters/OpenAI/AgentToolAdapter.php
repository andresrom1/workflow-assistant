<?php
// app/Adapters/OpenAI/AgentToolAdapter.php

namespace App\Adapters\OpenAI;

use App\Services\CustomerIdentificationService;
use Illuminate\Support\Facades\Log;

class AgentToolAdapter
{
    public function __construct(
        private readonly CustomerIdentificationService $customerService,
    ) {}

    public function identifyCustomer(array $payload): array
    {
        Log::info('Adapter: identifyCustomer llamado', $payload);
        try {
            $threadId = "aca deberia ir el thread_id de openai";
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

    private function formatError(string $msg, string $code): array
    {
        return [
            'success'    => false,
            'error'      => $msg,
            'error_code' => $code,
        ];
    }
}