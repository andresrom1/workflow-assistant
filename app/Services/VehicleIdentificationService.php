<?php

namespace App\Services;

use App\Repositories\VehicleRepository;
use App\Repositories\ConversationRepository;
use Illuminate\Support\Facades\Log;

class VehicleIdentificationService
{
    protected VehicleRepository $vehicleRepository;
    protected ConversationRepository $conversationRepository;

    public function __construct(
        VehicleRepository $vehicleRepository,
        ConversationRepository $conversationRepository
    ) {
        $this->vehicleRepository = $vehicleRepository;
        $this->conversationRepository = $conversationRepository;
    }

    public function identifyVehicle(
        string $patente,
        string $marca,
        string $modelo,
        string $version,
        int $year,
        string $combustible,
        string $codigoPostal,
        string $openaiUserId,
        string $threadId
    ): array {
        try {
            Log::info(__METHOD__ . __LINE__, [
                'identifyVehicle SERVICE',

            ]);
            // 1. Buscar conversación activa
            $conversation = $this->conversationRepository
                ->findActiveByOpenAIUserId($threadId);

            if (!$conversation) {
                throw new \Exception('No active conversation found');
            }

            // 2. Buscar o crear vehículo
            $vehicle = $this->vehicleRepository->findOrCreate([
                'patente' => $patente,
                'marca' => $marca,
                'modelo' => $modelo,
                'version' => $version,
                'year' => $year,
                'combustible' => $combustible,
                'codigo_postal' => $codigoPostal,
                'customer_id' => $conversation->customer_id,
                'thread_id' => $threadId,
            ]);

            // 3. Vincular vehículo con el cliente de la conversación
            if ($conversation->customer_id && !$vehicle->customer_id) {
                $this->vehicleRepository->linkToCustomer(
                    $vehicle->id,
                    $conversation->customer_id
                );
                $vehicle->refresh();
            }

            // 4. Verificar si el vehículo está completo
            $vehicle->checkCompleteness();

            // 5. Actualizar actividad de la conversación
            $this->conversationRepository->updateActivity($openaiUserId);

            Log::info('Vehicle identified successfully', [
                'vehicle_id' => $vehicle->id,
                'customer_id' => $conversation->customer_id,
                'is_complete' => $vehicle->is_complete,
            ]);

            return [
                'success' => true,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $conversation->customer_id,
                'is_complete' => $vehicle->is_complete,
                'message' => 'Vehículo identificado correctamente',
                'vehicle_data' => [
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'version' => $vehicle->version,
                    'year' => $vehicle->year,
                    'combustible' => $vehicle->combustible,
                    'codigo_postal' => $vehicle->codigo_postal,
                ],
                'next_step' => $vehicle->is_complete ? 'coverage_selection' : 'complete_vehicle_data',
                'suggested_message' => $vehicle->is_complete 
                    ? '¿Te gustaría una cobertura básica o algo más completo?'
                    : 'Necesito algunos datos adicionales del vehículo.',
            ];
        } catch (\Exception $e) {
            Log::error('Error identifying vehicle', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'vehicle_identification_failed',
            ];
        }
    }
}