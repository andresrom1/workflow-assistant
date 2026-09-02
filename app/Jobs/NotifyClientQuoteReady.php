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
     * nunca. El presupuesto tiene que superar el máximo que otro job puede retener el lock, y
     * ese máximo se mide contra el `expireAfter` del lock (450s) y no contra el `$timeout`
     * del job: al job que muere lo mata el alarm y **no suelta el lock** — lo suelta el
     * vencimiento. 46 × `releaseAfter(10)` = 460s > 450s. Los fallos reales los acota
     * `maxExceptions`.
     */
    public int $tries = 47;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    /**
     * Por debajo del `retry_after` de `database_ai` (450s). Este job corre un turno completo del
     * orquestador —QuoteAgent con el JSON de alternativas, encadenado a CheckoutAgent—, o sea
     * DOS llamadas al LLM con tope de 180s cada una.
     *
     * Eran 180s para las dos, contra una medición de ~50s que resultó optimista: el 2026-09-02
     * el par tardó 17,9s + más de 160s y el alarm mató el proceso dos segundos después de que
     * `present_quote_options` escribiera, con el texto todavía generándose. Ver ROADMAP.
     */
    public int $timeout = 400;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $quoteId,
    ) {
        $this->onQueue('whatsapp-ai');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("inbox:{$this->conversationId}"))
                ->releaseAfter(10)
                // Tiene que superar el `timeout` del job (400s): con 120s el lock se soltaba
                // solo mientras el turno seguía corriendo y otro job entraba en paralelo sobre
                // la misma conversación.
                ->expireAfter(450),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            Log::warning('NotifyClientQuoteReady: conversación no encontrada', ['conversation_id' => $this->conversationId]);

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

        // El guard mira la ENTREGA, no el flag que prende la tool. `quote_ready` lo enciende
        // `GetQuoteTool` a mitad del turno, y entre eso y el despacho puede morir el proceso: con
        // el flag como guard, todos los reintentos salían por acá y el cliente no recibía nada
        // nunca. `presented_at` lo sella `despacharRespuesta()`. Ver ROADMAP, bitácora 2026-09-02.
        if ($quote->presented_at !== null) {
            Log::info('NotifyClientQuoteReady: cotización ya entregada, saliendo', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        // Estado de presentada sin entrega: el turno anterior murió en el medio. El estado no era
        // cierto —el cliente nunca vio las alternativas—, así que se vuelve atrás y se rehace el
        // turno completo. Con `quote_ready` en false el orquestador entrega QuoteAgent, que es el
        // único con `get_quote` y puede rearmar el payload desde cero.
        if ($conversation->aiState()['quote_ready']) {
            Log::warning('NotifyClientQuoteReady: presentación sin entregar, se rehace el turno', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            $conversation->updateAiState(['quote_ready' => false]);
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

        $trigger = $conversation->aiState()['coverage_set']
            ? $this->pedidoDePresentacion()
            : $this->pedidoDeCobertura($conversation);

        if ($trigger === null) {
            return;
        }

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

    private function pedidoDePresentacion(): string
    {
        return 'Las cotizaciones ya están listas. '
            ."Usá tu herramienta para obtener la cotización número {$this->quoteId} "
            .'y presentá todas las alternativas disponibles al cliente ahora.';
    }

    /**
     * Red de seguridad: la cotización terminó y la cobertura sigue sin registrar.
     *
     * Presentar acá se saltearía la pregunta de cobertura, así que no se presenta. Pero
     * tampoco se puede salir en silencio: si el turno que dejó la cotización en vuelo cerró
     * prometiendo las opciones —el cliente ya había dicho la cobertura, así que el agente no
     * tenía nada que preguntar— no hay ningún mensaje entrante por venir que despierte el
     * flujo, y la conversación queda muerta con la cotización lista. Pasó en producción con
     * la conversación 26; ver ROADMAP, bitácora 2026-09-02.
     *
     * El camino normal lo cubre el encadenamiento de turno del orquestador. Esto es el
     * respaldo, anclado en el estado y no en el camino: sirva cual sirva la ruta que llegó
     * hasta acá, el cliente recibe un mensaje en vez de un silencio.
     *
     * Devuelve null cuando no corresponde abrir un turno.
     */
    private function pedidoDeCobertura(Conversation $conversation): ?string
    {
        if ($conversation->isAiPaused()) {
            Log::info('NotifyClientQuoteReady: cobertura sin elegir y IA pausada, no se abre turno', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            return null;
        }

        Log::info('NotifyClientQuoteReady: cobertura todavía sin elegir, se pide en vez de presentar', [
            'conversation_id' => $this->conversationId,
            'quote_id' => $this->quoteId,
        ]);

        return 'La consulta a las compañías ya terminó, pero todavía no quedó registrada la cobertura que quiere el cliente. '
            .'Si ya la dijo en algún mensaje anterior, registrala ahora con coverage_preference sin volver a preguntársela. '
            .'Si no la dijo, preguntásela en una sola frase. No presentes alternativas ni precios en este mensaje.';
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
