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
     * @param  string  $preference  Código de cobertura: A | B | C | D.
     * @param  array{coberturas_requeridas?: list<string>, reasoning?: string}|null  $metadata  Coberturas concretas que pidió el cliente, además del nivel.
     * @return array<string, mixed>
     */
    public function saveCoveragePreference(int $conversationId, int $vehicleId, string $preference, ?array $metadata = null): array
    {
        return $this->coverageRepo->saveCoveragePreference(
            $conversationId,
            $vehicleId,
            $preference,
            $metadata
        )->toArray();
    }
}
