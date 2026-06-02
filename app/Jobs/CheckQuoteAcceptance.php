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

class CheckQuoteAcceptance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Quote $quote,
        protected RiskSnapshot $snapshot
    ) {}

    /**
     * Vigilante de abandono: si la quote sigue 'pending' al vencer el timeout
     * (el usuario nunca llegó a la preferencia de cobertura), dispara la resolución.
     */
    public function handle(QuoteService $quoteService): void
    {
        Log::info("[CheckQuoteAcceptance] Verificando estado de Quote #{$this->quote->id}");

        // Solo resolvemos si quedó colgada en 'pending'; cualquier otro estado ya avanzó.
        if ($this->quote->status !== 'pending') {
            Log::info("[CheckQuoteAcceptance] Quote #{$this->quote->id} ya no requiere fallback (Status: {$this->quote->status}).");

            return;
        }

        Log::warning("[CheckQuoteAcceptance] Timeout alcanzado para Quote #{$this->quote->id} (Estado: pending). Iniciando resolución.");

        $quoteService->resolveQuote($this->quote, $this->snapshot);
    }
}
