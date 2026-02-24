<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteProviderRef;
use App\Models\RiskSnapshot;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;

class QuoteRepository
{
    use ConditionalLogger;

    /**
     * Crea la cabecera de la cotización en estado pendiente.
     *
     * @param  RiskSnapshot  $snapshot  // El snapshot del riesgo
     * @param  Conversation  $conversation  // La conversacion en donde se crea la cotización
     * @param  string  $sessionUuid  El UUID de la sesión para rastreo.
     */
    public function createPending(RiskSnapshot $snapshot, Conversation $conversation, string $sessionUuid): Quote
    {
        return Quote::create([
            'session_uuid' => $sessionUuid,
            'risk_snapshot_id' => $snapshot->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Guarda los resultados del motor de cotización de forma atómica.
     * Maneja la limpieza de alternativas previas (idempotencia) y la actualización de estado.
     *
     * @param  array  $engineResult  El resultado raw del QuotingEngine
     */
    public function saveResults(Quote $quote, array $engineResult): void
    {
        DB::transaction(function () use ($quote, $engineResult) {

            // 1. Limpieza preventiva (idempotencia para reintentos del job)
            $quote->alternatives()->delete();
            $quote->providerRef()->delete();

            // 2. Actualizar cabecera (sin raw_response — movido a quote_provider_refs)
            $quote->update([
                'status' => 'processed',
                'external_ref_id' => $engineResult['task_id'] ?? null,
                'expires_at' => now()->addDays(7),
            ]);

            // 3. Insertar alternativas de dominio (sin campos de proveedor)
            if (! empty($engineResult['parsed_alternatives'])) {
                $domainAlternatives = array_map(function (array $alt): array {
                    unset($alt['external_quote_id'], $alt['external_code']);

                    return $alt;
                }, $engineResult['parsed_alternatives']);

                $quote->alternatives()->createMany($domainAlternatives);
            }

            // 4. Persistir referencia de proveedor (auditoría)
            QuoteProviderRef::create([
                'quote_id' => $quote->id,
                'external_quote_id' => $engineResult['parsed_alternatives'][0]['external_quote_id'] ?? null,
                'raw_response' => $engineResult['raw'] ?? ['alternatives' => $engineResult['parsed_alternatives']],
            ]);
        });

        $this->logQuote("[QuoteRepo] Resultados guardados para Quote ID: {$quote->id}");
    }

    /**
     * Marca la cotización como fallida.
     */
    public function markAsFailed(Quote $quote, string $errorMessage): void
    {
        $quote->update([
            'status' => 'failed',
            'metadata' => ['error' => $errorMessage],
        ]);
    }

    /**
     * Obtiene una cotización por ID con o sin alternativas.
     *
     * @param  int  $id  El id de la Quote
     * @param  bool  $withAlternatives  Indica si se quieren las alternativas de la Quote
     */
    public function getById(int $id, bool $withAlternatives = false): ?Quote
    {
        return $withAlternatives
            ? Quote::with('alternatives')->find($id)
            : Quote::find($id);
    }
}
