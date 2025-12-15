<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteOption;
use App\Models\RiskSnapshot;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class QuoteRepository
{
    use ConditionalLogger;

    /**
     * Crea la cabecera de la cotización en estado pendiente.
     * @param RiskSnapshot $snapshot // El snapshot del riesgo
     * @param Conversation $conversation // La conversacion en donde se crea la cotización
     * @return Quote
     */
    public function createPending(RiskSnapshot $snapshot, Conversation $conversation): Quote
    {
        return Quote::create([
            'risk_snapshot_id' => $snapshot->id,
            'conversation_id'  => $conversation->id,
            'status'           => 'pending',
        ]);
    }

    /**
     * Guarda los resultados del motor de cotización de forma atómica.
     * Maneja la limpieza de alternativas previas (idempotencia) y la actualización de estado.
     * * @param Quote $quote
     * @param array $engineResult El resultado raw del QuotingEngine
     */
    public function saveSimulationResults(Quote $quote, array $engineResult): void
    {
        DB::transaction(function () use ($quote, $engineResult) {
            
            // 1. Limpieza Preventiva (Idempotencia para reintentos del Job)
            // Delegamos al modelo la relación, pero la acción es del repo.
            $quote->alternatives()->delete();

            // 2. Actualizar Cabecera
            $quote->update([
                'status'          => 'processed',
                'external_ref_id' => $engineResult['task_id'] ?? null,
                'raw_response'    => $engineResult['raw'] ?? [],
                'expires_at'      => now()->addDays(7),
            ]);

            // 3. Insertar Nuevas Alternativas
            // createMany es más eficiente y limpio que un foreach en el Job.
            if (!empty($engineResult['parsed_alternatives'])) {
                $quote->alternatives()->createMany($engineResult['parsed_alternatives']);
            }
        });
        
        $this->logQuote("[QuoteRepo] Resultados guardados para Quote ID: {$quote->id}");
    }

    /**
     * Marca la cotización como fallida.
     */
    public function markAsFailed(Quote $quote, string $errorMessage): void
    {
        $quote->update([
            'status'   => 'failed',
            'metadata' => ['error' => $errorMessage]
        ]);
    }
}