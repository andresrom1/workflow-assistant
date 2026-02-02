<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Jobs\RequestQuotesFromProviders;
use App\Repositories\QuoteRepository;
use App\Repositories\RiskSnapshotRepository;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;

class QuoteService
{
    use ConditionalLogger;
    public function __construct(
        private readonly RiskSnapshotRepository $snapshotRepo,
        private readonly QuoteRepository $quoteRepo
    ) {}
    /**
     * Inicia el proceso de cotización tomando un snapshot del riesgo actual 
     * y creando una Quote en estado 'pending'.
     *
     * @param Conversation $conversation
     * @param Customer $customer
     * @param Vehicle $vehicle
     * @param string $sessionUuid El UUID de la sesión para rastreo.
     * @return Quote La instancia de la Quote creada.
     */
    
    public function createPendingQuote(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid): Quote
    {
        // 1. Bloque de Transacción para garantizar Atomicidad
        // Si falla la creación del Snapshot, no se crea la Quote.
        $quote = DB::transaction(function () use ($conversation, $customer, $vehicle, $sessionUuid) {

            // 1.1. CONGELAR EL RIESGO (SNAPSHOT)
            $snapshot = $this->snapshotRepo->createFromEntities($customer, $vehicle);

            // 1.2. INICIAR LA TRANSACCIÓN (QUOTE en estado PENDING)
            $quote    = $this->quoteRepo->createPending($snapshot, $conversation, $sessionUuid);

            return $quote;
        });

        $this->logQuotes("[QuoteService🫰] Created pending Quote ID: {$quote->id} for Conversation ID: {$conversation->id}");
        
        // 2. DISPARAR PROCESO ASÍNCRONO
        // Usamos un Job para hacer el trabajo pesado (consultar a proveedores, parsear HTML).
        // Esto libera inmediatamente al AgentToolAdapter y evita el bloqueo.
        RequestQuotesFromProviders::dispatch($quote);

        return $quote;
    }
    /**
     * Devuelve el Raw Response 
     * @param Quote $quote
     * @return array
     */
    public function getRaw(Quote $quote)
    {
        if (!$quote->raw_response) {
            return [
                'status' => 'pending',
                'message' => 'La cotización aún está en proceso. Por favor, inténtelo más tarde.'
            ];
        }
        
        return $this->quoteRepo->getRawJson($quote);
    }
}