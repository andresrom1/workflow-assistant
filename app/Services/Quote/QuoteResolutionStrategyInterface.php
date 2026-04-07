<?php

namespace App\Services\Quote;

use App\Models\Quote;
use App\Models\RiskSnapshot;

interface QuoteResolutionStrategyInterface
{
    /**
     * Resuelve la cotización utilizando la estrategia específica.
     */
    public function resolve(Quote $quote, RiskSnapshot $snapshot): void;

    /**
     * Determina si esta estrategia puede manejar la cotización.
     */
    public function canHandle(Quote $quote, RiskSnapshot $snapshot): bool;

    /**
     * Devuelve el nombre de la estrategia.
     */
    public function getName(): string;
}
