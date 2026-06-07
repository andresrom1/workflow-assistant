<?php

namespace App\Services\Quote\Strategies;

use App\Contracts\QuotationProvider;
use App\Events\QuoteProcessed;
use App\Jobs\NotifyClientQuoteReady;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Repositories\QuoteRepository;
use App\Services\Quote\QuoteResolutionStrategyInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiQuoteResolution implements QuoteResolutionStrategyInterface
{
    public function __construct(
        private readonly QuotationProvider $engine,
        private readonly QuoteRepository $quoteRepo
    ) {}

    public function resolve(Quote $quote, RiskSnapshot $snapshot): void
    {
        try {
            Log::info(__METHOD__." Resolviendo Quote ID: {$quote->id} vía API");

            // 1. Marcar el método de resolución
            $quote->update(['resolution_method' => 'api']);

            // 2. Generar alternativas (vía el puerto: mock o Visred según config)
            $result = $this->engine->generateAlternatives($snapshot);

            // 3. Guardar resultados
            $this->quoteRepo->saveResults($quote, $result);

            // 4. Notificar según el canal de origen
            $quote->loadMissing('conversation');

            if ($quote->conversation?->channel === 'whatsapp') {
                NotifyClientQuoteReady::dispatch($quote->conversation->id, $quote->id)
                    ->onConnection('database_ai')
                    ->onQueue('whatsapp-ai');
            } else {
                QuoteProcessed::dispatch($quote);
            }

        } catch (Throwable $e) {
            Log::error('[ApiQuoteResolution] Fallo: '.$e->getMessage(), [
                'quote_id' => $quote->id,
                'trace' => $e->getTraceAsString(),
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
