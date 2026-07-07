<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Repositories\QuoteRepository;
use App\Repositories\RiskSnapshotRepository;
use App\Services\Quote\Strategies\ApiQuoteResolution;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuoteService
{
    use ConditionalLogger;

    public function __construct(
        private readonly RiskSnapshotRepository $snapshotRepo,
        private readonly QuoteRepository $quoteRepo,
        private readonly ApiQuoteResolution $apiStrategy,
    ) {}

    /**
     * Inicia el proceso de cotización tomando un snapshot del riesgo actual
     * y creando una Quote en estado 'pending'.
     */
    public function createPendingQuote(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid, ?string $coveragePreference = null): Quote
    {
        $quote = DB::transaction(function () use ($conversation, $customer, $vehicle, $sessionUuid, $coveragePreference): Quote {
            $snapshot = $this->snapshotRepo->createFromEntities($customer, $vehicle, $coveragePreference);

            return $this->quoteRepo->createPending($snapshot, $conversation, $sessionUuid);
        });

        $this->logQuotes("[QuoteService🫰] Created pending Quote ID: {$quote->id}");

        return $quote;
    }

    /**
     * Resuelve la cotización. Hoy hay una única estrategia (`api`);
     * el seam queda listo para enchufar Visred detrás del mismo punto.
     */
    public function resolveQuote(Quote $quote, RiskSnapshot $snapshot): bool
    {
        Log::info("[QuoteService] Resolviendo Quote #{$quote->id} con estrategia: {$this->apiStrategy->getName()}");

        try {
            $this->apiStrategy->resolve($quote, $snapshot);

            return true;
        } catch (\Throwable $e) {
            Log::error("[QuoteService] Error resolviendo Quote #{$quote->id}", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Actualiza la preferencia de cobertura en el snapshot de una cotización.
     */
    public function updateSnapshotPreference(Quote $quote, string $preference): void
    {
        $this->snapshotRepo->updateCoveragePreference($quote->riskSnapshot, $preference);
    }

    /**
     * Expira las quotes abiertas de la conversación. Se usa al revertir una etapa
     * (el cliente corrigió el vehículo o la cobertura): la cotización en curso ya
     * no representa el riesgo real y debe descartarse. Idempotente.
     */
    public function expireOpenQuotes(Conversation $conversation): int
    {
        return $conversation->quotes()
            ->whereIn('status', ['pending', 'processed', 'checkout_pending'])
            ->update(['status' => 'expired']);
    }

    public function getRaw(Quote $quote): array
    {
        $quote->loadMissing('providerRef', 'alternatives');

        if ($quote->alternatives->isEmpty()) {
            return [
                'status' => $quote->status,
                'message' => 'La cotización aún está en proceso.',
            ];
        }

        return [
            'external_quote_id' => $quote->providerRef?->external_quote_id,
            'raw_response' => $quote->providerRef?->raw_response,
            'alternatives' => $quote->alternatives->toArray(),
        ];
    }

    /**
     * Obtiene una cotización por ID.
     *
     * @param  int  $quote  ID de la cotización.
     * @param  bool  $withAlternatives  Indica si se deben incluir las alternativas.
     * @return Quote La cotización obtenida.
     */
    public function getQuote(int $quote, bool $withAlternatives = false): Quote
    {
        return $this->quoteRepo->getById($quote, $withAlternatives);
    }
}
