<?php

namespace App\Repositories;

use App\Models\CoveragePreference;
use App\Traits\ConditionalLogger;

class CoverageRepository
{
    use ConditionalLogger;

    /**
     * Summary of saveCoveragePreference
     */
    public function saveCoveragePreference(int $conversationId, int $vehicleId, string $preference): CoveragePreference
    {
        return CoveragePreference::updateOrCreate(
            [
                'conversation_id' => $conversationId,
                'vehicle_id' => $vehicleId,
            ],
            [
                'preference' => $preference,
            ]
        );
    }
}
