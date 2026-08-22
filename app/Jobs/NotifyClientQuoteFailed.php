<?php

namespace App\Jobs;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por WhatsApp que la cotización no se pudo completar.
 *
 * Antes este camino no existía: la consulta corría dentro del turno y el error volvía al LLM como
 * `tool_output`, que improvisaba la disculpa. Con {@see ResolveQuote} fuera del turno ya no hay
 * ningún modelo escuchando, así que el aviso tiene que salir por su cuenta.
 *
 * **Texto fijo, sin LLM** — a propósito. Si algo se está cayendo, meter una llamada al modelo en el
 * camino de error agrega otra cosa que puede fallar justo cuando menos conviene.
 *
 * Idempotente por `quote_id` ({@see NotifyClientEmissionFailed} usa el mismo patrón): lo despachan
 * dos caminos distintos de `ResolveQuote` — el retorno `false` y `failed()` — y el cliente no puede
 * recibir la disculpa dos veces.
 */
class NotifyClientQuoteFailed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /** Arma un texto fijo y despacha el envío: no llama al LLM. */
    public int $timeout = 30;

    public function __construct(
        private readonly int $quoteId,
    ) {
        $this->onQueue('whatsapp-outbound');
    }

    public function handle(): void
    {
        $cacheKey = "quote_failed_notified_{$this->quoteId}";

        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return;
        }

        $quote = Quote::with('conversation')->find($this->quoteId);

        if (! $quote || ! $quote->conversation) {
            Cache::forget($cacheKey);
            Log::info('NotifyClientQuoteFailed: sin conversación de origen, saliendo', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        $conversation = $quote->conversation;

        // Destinatario: se envía por BSUID (recipient); el teléfono del cliente, si lo tenemos,
        // tiene precedencia (formato `to`, sin '+'). Ver WhatsAppOutboundService::recipientPayload.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->recipientPhone();
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! is_string($phoneNumberId) || $phoneNumberId === '') {
            Cache::forget($cacheKey);
            Log::error('NotifyClientQuoteFailed: destinatario o phoneNumberId no disponibles', [
                'quote_id' => $this->quoteId,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $text = 'Tuve un inconveniente al consultar las cotizaciones con las compañías. '
            .'Un Productor Asesor va a revisarlo y te contacta por acá. Disculpá la demora.';

        SendWhatsAppMessage::dispatch($phone, $bsuid, $text, $phoneNumberId, $conversation->id, 'quote_failed_notice');
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget("quote_failed_notified_{$this->quoteId}");

        Log::error('NotifyClientQuoteFailed: Job falló definitivamente', [
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
