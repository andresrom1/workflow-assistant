<?php

namespace App\Services\Quote\Strategies;

use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\Quote\QuoteResolutionStrategyInterface;
use App\Services\QuotingEngine;
use App\Repositories\QuoteRepository;
use App\Events\QuoteProcessed;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiQuoteResolution implements QuoteResolutionStrategyInterface
{
    public function __construct(
        private readonly QuotingEngine $engine,
        private readonly QuoteRepository $quoteRepo
    ) {
    }

    public function resolve(Quote $quote, RiskSnapshot $snapshot): void
    {
        try {
            Log::info(__METHOD__ . " Resolviendo Quote ID: {$quote->id} vía API");

            // 1. Marcar el método de resolución
            $quote->update(['resolution_method' => 'api']);

            // 2. Generar alternativas (usa el QuotingEngine existente)
            $result = $this->engine->generateAlternatives($snapshot);

            // 3. Guardar resultados
            $this->quoteRepo->saveResults($quote, $result);

            // 4. Notificar
            QuoteProcessed::dispatch($quote);

        } catch (Throwable $e) {
            Log::error("[ApiQuoteResolution] Fallo: " . $e->getMessage(), [
                'quote_id' => $quote->id,
                'trace' => $e->getTraceAsString()
            ]);

            $this->quoteRepo->markAsFailed($quote, $e->getMessage());

            throw $e;
        }
    }

    public function canHandle(Quote $quote, RiskSnapshot $snapshot): bool
    {
        // Por ahora manejamos todo con API como fallback
        return true;
    }

    public function getName(): string
    {
        return 'api';
    }
}
