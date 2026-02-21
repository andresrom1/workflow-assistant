<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Log;
use App\Models\CoveragePreference;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;

class CoverageRepository
{
    use ConditionalLogger;

    /**
     * Summary of saveCoveragePreference
     * @param int $conversationId
     * @param int $vehicleId
     * @param string $preference
     * @return CoveragePreference
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