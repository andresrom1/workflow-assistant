<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Repositories\QuoteRepository;
use App\Services\QuotingEngine;
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

    public $tries = 3;
    public $backoff = [2, 5, 10];

    public function __construct(
        protected Quote $quote,
    ) {}

    /**
     * El Job ahora es un orquestador puro entre el Motor de Cálculo y el Repositorio de Persistencia.
     */
    public function handle(QuotingEngine $engine, QuoteRepository $quoteRepo): void
    {
        Log::info(__METHOD__.__line__."[Job] Iniciando QuotingEngine para Quote ID: {$this->quote->id}");

        $snapshot = $this->quote->riskSnapshot;

        try {
            // 1. CÁLCULO (Sin efectos secundarios)
            $result = $engine->generateAlternatives($snapshot);
            
            // 2. PERSISTENCIA (Delegada al Repo)
            // El repo maneja internamente la transacción, limpieza e inserción.
            $quoteRepo->saveSimulationResults($this->quote, $result);

            Log::info("[Job] Cotización finalizada exitosamente.");

        } catch (Throwable $e) {
            Log::error("[Job] Fallo crítico: " . $e->getMessage(), [
                'quote_id' => $this->quote->id,
                'trace'    => $e->getTraceAsString()
            ]);
            
            // El Repo maneja la lógica de marcar como fallido
            $quoteRepo->markAsFailed($this->quote, $e->getMessage());
            
            $this->fail($e); 
        }
    }
}