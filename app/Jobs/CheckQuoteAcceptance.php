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
     * Verifica si la cotización fue aceptada.
     * Si sigue en estado 'offered_pas', dispara el fallback a la API.
     */
    public function handle(QuoteService $quoteService): void
    {
        Log::info("[CheckQuoteAcceptance] Verificando estado de Quote #{$this->quote->id}");

        // Si ya fue procesada o está en otro estado final, no hacemos nada.
        // Permitimos el fallback si está en 'pending' (el usuario abandonó antes de la cobertura)
        // o en 'offered_pas' (el PAS nunca respondió).
        if ($this->quote->status !== 'pending' && $this->quote->status !== 'offered_pas') {
            Log::info("[CheckQuoteAcceptance] Quote #{$this->quote->id} ya no requiere fallback (Status: {$this->quote->status}).");

            return;
        }

        Log::warning("[CheckQuoteAcceptance] Timeout alcanzado para Quote #{$this->quote->id} (Estado: {$this->quote->status}). Iniciando fallback a API.");

        // Marcamos como 'rejected_pas' si venía de ahí, o simplemente procedemos al fallback.
        if ($this->quote->status === 'offered_pas') {
            $this->quote->update(['status' => 'rejected_pas']);
        }

        // Llamamos al servicio para resolver vía API (que no requiere la preferencia de cobertura)
        $quoteService->resolveQuote($this->quote, $this->snapshot, 'api');
    }
}
