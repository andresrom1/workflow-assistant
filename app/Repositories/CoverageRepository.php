<?php

namespace App\Repositories;

use App\Models\CoveragePreference;
use App\Traits\ConditionalLogger;

class CoverageRepository
{
    use ConditionalLogger;

    /**
     * Guarda (o actualiza) la cobertura elegida por el cliente para un vehículo.
     *
     * @param  string  $preference  Código de cobertura: A | B | C | D.
     * @param  array{coberturas_requeridas?: list<string>, reasoning?: string}|null  $metadata
     *                                                                                          Lo que el cliente pidió además del nivel. El nivel solo no alcanza: dos clientes que
     *                                                                                          dicen "terceros completo" y "terceros completo con granizo" se guardaban idénticos, y
     *                                                                                          el closer terminaba ofreciendo la más barata del nivel sin el granizo. `null` deja el
     *                                                                                          valor anterior en vez de pisarlo (el path legacy de OpenAI no lo manda).
     */
    public function saveCoveragePreference(int $conversationId, int $vehicleId, string $preference, ?array $metadata = null): CoveragePreference
    {
        return CoveragePreference::updateOrCreate(
            [
                'conversation_id' => $conversationId,
                'vehicle_id' => $vehicleId,
            ],
            array_filter(
                ['preference' => $preference, 'metadata' => $metadata],
                fn (mixed $valor): bool => $valor !== null,
            )
        );
    }
}
