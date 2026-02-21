<?php

namespace App\Services;

use App\Jobs\CheckQuoteAcceptance;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Jobs\RequestQuotesFromProviders;
use App\Repositories\QuoteRepository;
use App\Repositories\RiskSnapshotRepository;
use App\Services\Quote\QuoteResolutionStrategyInterface;
use App\Services\Quote\Strategies\ApiQuoteResolution;
use App\Services\Quote\Strategies\MobileAppQuoteResolution;
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
        private readonly MobileAppQuoteResolution $mobileStrategy,
        //private readonly CheckQuoteAcceptance $checkQuoteAcceptance, //Se elimina para ser despachado estaticamente.
    ) {
    }

    /**
     * Inicia el proceso de cotización tomando un snapshot del riesgo actual 
     * y creando una Quote en estado 'pending'.
     */
    public function createPendingQuote(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid, ?string $coveragePreference = null): Quote
    {
        $transaction = DB::transaction(function () use ($conversation, $customer, $vehicle, $sessionUuid, $coveragePreference) {
            $snapshot = $this->snapshotRepo->createFromEntities($customer, $vehicle, $coveragePreference);
            $quote = $this->quoteRepo->createPending($snapshot, $conversation, $sessionUuid);
            return ['quote' => $quote, 'snapshot' => $snapshot];
        });

        $quote = $transaction['quote'];
        $snapshot = $transaction['snapshot'];


        $this->logQuotes("[QuoteService🫰] Created pending Quote ID: {$quote->id}");
        // Programar el Job de Fallback (Vigilante) desde ahora.
        $timeout = (int) config('services.mobile_app.timeout_minutes', 30);
        Log::info("El tipo de deto de timeout es: ", [gettype($timeout)]);

        // $this->checkQuoteAcceptance::dispatch($quote, $snapshot)
        //     ->delay(now()->addMinutes($timeout));

        CheckQuoteAcceptance::dispatch($quote, $snapshot) //Se elimina del constructor. Se despacha estaticamente
            ->delay(now()->addMinutes($timeout));

        return $quote;
    }

    /**
     * Resuelve la cotización utilizando la estrategia indicada o la de prioridad.
     */
    // public function resolveQuote(Quote $quote, RiskSnapshot $snapshot, ?string $strategyName = null): void
    // {
    //     $strategy = $this->selectStrategy($quote, $snapshot, $strategyName);

    //     Log::info("[QuoteService] Resolviendo Quote #{$quote->id} con estrategia: {$strategy->getName()}");

    //     $strategy->resolve($quote, $snapshot);
    // }
    public function resolveQuote(Quote $quote, RiskSnapshot $snapshot, ?string $strategyName = null): bool
    {
        $strategy = $this->selectStrategy($quote, $snapshot, $strategyName);

        Log::info("[QuoteService] Resolviendo Quote #{$quote->id} con estrategia: {$strategy->getName()}");

        try {
            $strategy->resolve($quote, $snapshot);
            return true;
        } catch (\Throwable $e) {
            Log::error("[QuoteService] Error resolviendo Quote #{$quote->id}", [
                'strategy' => $strategy->getName(),
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
     * Selecciona la mejor estrategia disponible o la solicitada.
     */
    private function selectStrategy(Quote $quote, RiskSnapshot $snapshot, ?string $preferred = null): QuoteResolutionStrategyInterface
    {
        if ($preferred === 'api') {
            return $this->apiStrategy;
        }

        if ($preferred === 'mobile') {
            return $this->mobileStrategy;
        }

        // Lógica de prioridad por defecto: Mobile primero si es posible
        if ($this->mobileStrategy->canHandle($quote, $snapshot)) {
            return $this->mobileStrategy;
        }

        return $this->apiStrategy;
    }

    public function getRaw(Quote $quote)
    {
        if (!$quote->raw_response) {
            return [
                'status' => $quote->status,
                'message' => 'La cotización aún está en proceso.'
            ];
        }

        return $this->quoteRepo->getRawJson($quote);
    }

    /**
     * Obtiene una cotización por ID.
     * @param int $quote ID de la cotización.
     * @param bool $withAlternatives Indica si se deben incluir las alternativas.
     * @return Quote La cotización obtenida.
     */
    public function getQuote(int $quote, bool $withAlternatives = false): Quote
    {
        return $this->quoteRepo->getById($quote, $withAlternatives);
    }
}