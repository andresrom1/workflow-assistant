<?php

namespace App\Services;

use App\Repositories\CoverageRepository;
use App\Traits\ConditionalLogger;

class CoveragePreferenceService
{
    use ConditionalLogger;

    public function __construct(
        private readonly CoverageRepository $coverageRepo,
    ) {}

    /**
     * Summary of saveCoveragePreference
     *
     * @param  string  $preference  // Codigo de cobertura
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
