<?php

namespace App\Services\Quote;

use App\Models\Quote;
use App\Models\RiskSnapshot;

interface QuoteResolutionStrategyInterface
{
    /**
     * Resuelve la cotización utilizando la estrategia específica.
     *
     * @param Quote $quote
     * @param RiskSnapshot $snapshot
     * @return void
     */
    public function resolve(Quote $quote, RiskSnapshot $snapshot): void;

    /**
     * Determina si esta estrategia puede manejar la cotización.
     *
     * @param Quote $quote
     * @param RiskSnapshot $snapshot
     * @return bool
     */
    public function canHandle(Quote $quote, RiskSnapshot $snapshot): bool;

    /**
     * Devuelve el nombre de la estrategia.
     *
     * @return string
     */
    public function getName(): string;
}
