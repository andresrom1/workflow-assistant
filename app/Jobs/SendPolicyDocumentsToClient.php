<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Poliza;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Envía por WhatsApp los documentos oficiales (`visible_to_client = true`) de una
 * póliza recién emitida. Puede dispararse dos veces para la misma póliza —al emitir
 * y, si algo quedó pendiente, cuando {@see CapturePendingPolicyDocuments} lo captura
 * después— por eso la idempotencia es por `poliza_id`, no por el disparador.
 */
class SendPolicyDocumentsToClient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(
        private readonly int $polizaId,
        private readonly int $conversationId,
    ) {}

    public function handle(WhatsAppOutboundService $waService): void
    {
        $cacheKey = "policy_docs_sent_{$this->polizaId}";

        // Cache::add es atómico: si ya está seteada (otro disparo ya envió, o está en
        // curso), no reintentamos. Si algo falla más abajo, liberamos la clave en el
        // catch para que un reintento (o el próximo disparo) pueda volver a intentar.
        if (! Cache::add($cacheKey, true, now()->addDays(7))) {
            return;
        }

        try {
            $poliza = Poliza::find($this->polizaId);
            $documents = $poliza?->documents()
                ->where('visible_to_client', true)
                ->whereNotNull('storage_url')
                ->get() ?? collect();

            if ($poliza === null || $documents->isEmpty()) {
                Cache::forget($cacheKey);

                return;
            }

            $conversation = Conversation::find($this->conversationId);

            if (! $conversation) {
                Cache::forget($cacheKey);
                Log::warning('SendPolicyDocumentsToClient: conversación no encontrada', [
                    'poliza_id' => $this->polizaId,
                    'conversation_id' => $this->conversationId,
                ]);

                return;
            }

            // Destinatario: se envía por BSUID (recipient); el teléfono del cliente, si lo
            // tenemos, tiene precedencia (formato `to`, sin '+'). Ver recipientPayload.
            $bsuid = $conversation->ext_user_id;
            $phone = $conversation->recipientPhone();
            $phoneNumberId = config('services.whatsapp.phone_number_id');

            if ((! $phone && ! $bsuid) || ! $phoneNumberId) {
                Cache::forget($cacheKey);
                Log::error('SendPolicyDocumentsToClient: destinatario o phoneNumberId no disponibles', [
                    'poliza_id' => $this->polizaId,
                    'conversation_id' => $this->conversationId,
                ]);

                return;
            }

            $caption = trim("Póliza {$poliza->numero}".($poliza->company ? " — {$poliza->company}" : ''));

            foreach ($documents as $document) {
                $waService->sendDocumentMessage(
                    $phone,
                    $bsuid,
                    (string) $document->storage_url,
                    $document->original_filename ?? "poliza-{$poliza->numero}.pdf",
                    $phoneNumberId,
                    $caption,
                    $this->conversationId,
                );
            }
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendPolicyDocumentsToClient: Job falló definitivamente', [
            'poliza_id' => $this->polizaId,
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
