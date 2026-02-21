<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Vehicle;
use App\Repositories\CoverageRepository;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Validator;

class CoveragePreferenceService
{
    use ConditionalLogger;

    public function __construct(
        private readonly CoverageRepository $coverageRepo,
    ) {
    }

    /**
     * Summary of saveCoveragePreference
     * @param int $conversationId
     * @param int $vehicleId
     * @param string $preference // Codigo de cobertura
     * @return array
     */
    public function saveCoveragePreference(int $conversationId, int $vehicleId, string $preference): array
    {
        return $this->coverageRepo->saveCoveragePreference(
            $conversationId,
            $vehicleId,
            $preference
        )->toArray();
    }
}