<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use App\Models\Quote;
use App\Traits\DespachaRespuestaDelAgente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyClientQuoteReady implements ShouldQueue
{
    use DespachaRespuestaDelAgente;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Alto a propósito, igual que en {@see ProcessConversationInbox}: este job espera el lock
     * `inbox:{id}` y cada `release()` del middleware consume un intento. Con la cotización
     * corriendo en paralelo al turno (ver {@see ResolveQuote}), puede terminar justo mientras el
     * cliente escribe — y con `tries = 2` moría contra el lock y la cotización no se entregaba
     * nunca. El presupuesto tiene que superar el máximo que otro job puede retener el lock
     * (180s): 24 × `releaseAfter(10)` = 240s. Los fallos reales los acota `maxExceptions`.
     */
    public int $tries = 25;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    /**
     * Por debajo del `retry_after` de `database_ai` (200s). Este job corre un turno completo del
     * orquestador —QuoteAgent con el JSON de alternativas, encadenado a CheckoutAgent—, que en
     * prod se midió en ~50s. Sin declararlo quedaba a merced del `--timeout` del worker.
     */
    public int $timeout = 180;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $quoteId,
    ) {
        $this->onConnection('database_ai');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("inbox:{$this->conversationId}"))
                ->releaseAfter(10)
                // Tiene que superar el `timeout` del job: con 120s el lock se soltaba solo
                // mientras el turno seguía corriendo y otro job entraba en paralelo sobre la
                // misma conversación.
                ->expireAfter(300),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            Log::warning('NotifyClientQuoteReady: conversación no encontrada', ['conversation_id' => $this->conversationId]);

            return;
        }

        if ($conversation->aiState()['quote_ready']) {
            Log::info('NotifyClientQuoteReady: cotización ya enviada, saliendo', ['conversation_id' => $this->conversationId]);

            return;
        }

        // La cotización arranca al identificar el vehículo, así que puede terminar ANTES de que el
        // cliente elija cobertura. Presentar acá se saltearía esa pregunta. Los resultados ya
        // quedaron guardados: cuando el cliente elija, `coveragePreference()` vuelve a despachar
        // este job. Regla: presenta el que completa la segunda de las dos condiciones.
        if (! $conversation->aiState()['coverage_set']) {
            Log::info('NotifyClientQuoteReady: cobertura todavía sin elegir, no se presenta', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        // Si el cliente revirtió etapa (revert_to_stage) mientras esta cotización
        // estaba en vuelo, quedó 'expired' — no tiene sentido presentarla.
        $quote = Quote::find($this->quoteId);
        if (! $quote || $quote->status === 'expired') {
            Log::info('NotifyClientQuoteReady: quote inexistente o expirada, saliendo', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        // Destinatario: se envía por BSUID (recipient); si tenemos el teléfono del cliente,
        // tiene precedencia (formato `to`, sin '+'). Ver WhatsAppOutboundService::recipientPayload.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->recipientPhone();
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! $phoneNumberId) {
            Log::error('NotifyClientQuoteReady: destinatario o phoneNumberId no disponibles', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $trigger = 'Las cotizaciones ya están listas. '
            ."Usá tu herramienta para obtener la cotización número {$this->quoteId} "
            .'y presentá todas las alternativas disponibles al cliente ahora.';

        $reply = $orchestrator->handle($trigger, $conversation);

        $this->despacharRespuesta(
            new SendWhatsAppMessage($phone, $bsuid, $reply['text'], $phoneNumberId, $this->conversationId, $reply['agent'], null, $reply['buttons'] ?? null),
            $reply['public_link'] ?? null,
            $phone,
            $bsuid,
            $phoneNumberId,
            $this->conversationId,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('NotifyClientQuoteReady: Job falló definitivamente', [
            'conversation_id' => $this->conversationId,
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
