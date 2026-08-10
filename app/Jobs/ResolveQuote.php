<?php

namespace App\Jobs;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta las cotizaciones a las compañías, fuera del turno del agente.
 *
 * Antes esta llamada corría sincrónicamente dentro de `WhatsAppAdapter::coveragePreference()`, o
 * sea adentro del job que atiende el turno: el polling contra Visred (un `while` con sleep de 4s
 * por task) dormía el proceso hasta 174s medidos en prod, contra un techo de 180s del worker. Al
 * pasarse, el proceso moría **después** de que las compañías respondieran y **antes** de que
 * `saveResults()` las guardara — se perdía la cotización entera. Ver ROADMAP, bitácora 2026-08-10.
 *
 * Además el turno retenía el lock `inbox:{id}` todo ese rato, así que el bot quedaba sordo: si el
 * cliente escribía "¿cuánto falta?", su mensaje hacía cola detrás. Por eso este job **no** lleva
 * ese lock — mientras corre, la conversación tiene que seguir andando.
 *
 * Se despacha al identificar el vehículo ({@see WhatsAppAdapter}), no al
 * elegir la cobertura: la request a Visred no incluye la preferencia de cobertura, así que no hay
 * nada que esperar y los 30-174s transcurren mientras el agente indaga.
 */
class ResolveQuote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Por debajo del `retry_after` de la conexión `database_quotes` (420s) y por encima del
     * `poll_budget` de Visred (240s).
     */
    public int $timeout = 360;

    public function __construct(
        private readonly int $quoteId,
    ) {
        $this->onConnection('database_quotes');
    }

    public function handle(QuoteService $quoteService): void
    {
        $quote = Quote::with('riskSnapshot')
            ->where('id', $this->quoteId)
            ->where('status', 'pending')
            ->first();

        // El cliente pudo revertir de etapa (revert_to_stage expira las quotes abiertas) o la
        // consulta pudo resolverse por otra vía mientras este job esperaba en la cola.
        if (! $quote instanceof Quote) {
            Log::info('ResolveQuote: la cotización ya no está pendiente, saliendo', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        if ($quote->riskSnapshot === null) {
            Log::error('ResolveQuote: la cotización no tiene snapshot de riesgo', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        // `resolveQuote()` atrapa sus propias excepciones y devuelve false, así que el fallo NO
        // llega por `failed()`: hay que avisarle al cliente desde acá. `failed()` cubre el otro
        // caso — que muera el job entero (timeout, OOM) —, y el aviso es idempotente por quote.
        if (! $quoteService->resolveQuote($quote, $quote->riskSnapshot)) {
            $this->avisarFallo();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ResolveQuote: Job falló definitivamente', [
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);

        $this->avisarFallo();
    }

    private function avisarFallo(): void
    {
        NotifyClientQuoteFailed::dispatch($this->quoteId)->onQueue('whatsapp-outbound');
    }
}
