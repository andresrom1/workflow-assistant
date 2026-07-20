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
 * Avisa por WhatsApp que la emisión falló, sin alarmar ni prometer plazos. No pasa
 * por el orquestador/LLM: el texto es siempre el mismo. Se despacha desde
 * `EmitirPoliza::failed()`, que puede correr más de una vez según el driver de
 * colas — idempotente por `quote_id` (visible para el cliente, a diferencia del
 * mail interno).
 *
 * Va a la cola `whatsapp-outbound` (mismo patrón que {@see SendPolicyDocumentsToClient}):
 * no hace trabajo de AI, así que no usa la conexión `database_ai`.
 */
class NotifyClientEmissionFailed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        private readonly int $quoteId,
    ) {}

    public function handle(): void
    {
        $cacheKey = "emission_failed_notified_{$this->quoteId}";

        if (! Cache::add($cacheKey, true, now()->addDays(7))) {
            return;
        }

        $quote = Quote::with('conversation')->find($this->quoteId);

        if (! $quote || ! $quote->conversation) {
            Cache::forget($cacheKey);
            Log::info('NotifyClientEmissionFailed: sin conversación de origen, saliendo', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        $conversation = $quote->conversation;

        // Destinatario: teléfono si la conversación lo tiene; si es solo-BSUID,
        // external_conversation_id == ext_user_id (ambos el BSUID) → no hay teléfono.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->external_conversation_id === $bsuid
            ? null
            : $conversation->external_conversation_id;
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! $phoneNumberId) {
            Cache::forget($cacheKey);
            Log::error('NotifyClientEmissionFailed: destinatario o phoneNumberId no disponibles', [
                'quote_id' => $this->quoteId,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $text = 'Tuvimos un inconveniente al gestionar la emisión de tu póliza con la compañía. '
            .'Ya lo estamos revisando y te confirmamos por acá apenas lo resolvamos. Disculpá la demora.';

        SendWhatsAppMessage::dispatch($phone, $bsuid, $text, $phoneNumberId, $conversation->id)
            ->onQueue('whatsapp-outbound');
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget("emission_failed_notified_{$this->quoteId}");

        Log::error('NotifyClientEmissionFailed: Job falló definitivamente', [
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
