<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\QuoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestQuotesFromProviders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Quote $quote,
        protected RiskSnapshot $snapshot
    ) {}

    /**
     * El Job ahora delega la resolución al QuoteService,
     * el cual orquestará la estrategia adecuada (Mobile o API).
     */
    public function handle(QuoteService $quoteService): void
    {
        Log::info("[Job] Iniciando resolución para Quote ID: {$this->quote->id}");

        try {
            $quoteService->resolveQuote($this->quote, $this->snapshot);
            Log::info('[Job] Resolución completada exitosamente.');
        } catch (Throwable $e) {
            Log::error('[Job] Fallo en la resolución: '.$e->getMessage());
            $this->fail($e);
        }
    }
}
