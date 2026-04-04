<?php

namespace App\Jobs;

use App\Models\Message;
use App\Repositories\ConversationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $waId,
        private readonly string $messageBody,
        private readonly string $messageId,
        private readonly string $phoneNumberId,
        private readonly string $contactName,
        private readonly string $messageType = 'text',
        private readonly ?string $extUserId = null,
        private readonly ?string $extUsername = null,
    ) {}

    public function handle(ConversationRepository $conversationRepo): void
    {
        // 1. Idempotencia — evita procesar el mismo mensaje dos veces si Meta reenvía el webhook.
        $cacheKey = 'processed_wamid_'.$this->messageId;
        if (Cache::has($cacheKey)) {
            Log::info('WhatsApp: mensaje duplicado ignorado', ['wamid' => $this->messageId]);

            return;
        }

        // 2. Solo procesamos mensajes de texto por ahora.
        if ($this->messageType !== 'text' || empty($this->messageBody)) {
            Cache::put($cacheKey, true, now()->addDay());

            return;
        }

        // 3. Obtener o crear la conversación — algoritmo dual-key:
        //    Primero por ext_user_id (BSUID, estable aunque el usuario cambie de número),
        //    luego fallback al wa_id (teléfono).
        $conversation = $this->extUserId
            ? $conversationRepo->findByExtUserId($this->extUserId)
            : null;

        if ($conversation) {
            // Si el usuario migró de número, actualizar el teléfono de la conversación.
            if ($conversation->external_conversation_id !== $this->waId) {
                $conversation->update(['external_conversation_id' => $this->waId]);
            }
        } else {
            $conversation = $conversationRepo->findOrCreateByExternalId($this->waId, 'whatsapp');
        }

        // Guardar ext_user_id y ext_username si aún no están (sin sobreescribir valores existentes).
        $conversation->updateExternalIdentifiers($this->extUserId, $this->extUsername);

        // 3b. Persistir el nombre del contacto en el Customer recurrente (customer_id ya vinculado).
        if ($this->contactName && $conversation->customer_id) {
            $conversation->load('customer');
            if ($conversation->customer && ! $conversation->customer->name) {
                $conversation->customer->update(['name' => $this->contactName]);
            }
        }

        // 3c. Persistir mensaje entrante (firstOrCreate evita duplicados por retry de Meta).
        //     processed_at queda null — el inbox processor lo marcará al procesarlo.
        Message::firstOrCreate(
            ['external_message_id' => $this->messageId],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'content' => $this->messageBody,
                'sender_name' => $this->contactName,
                'sender_phone' => $this->waId,
            ]
        );

        // 4. Marcar wamid como ingestado para evitar doble ingesta si Meta reenvía.
        Cache::put($cacheKey, true, now()->addDay());

        // 5. Despachar el inbox processor con delay de 2s (ventana de debounce).
        ProcessConversationInbox::dispatch($conversation->id, $this->waId, $this->phoneNumberId)
            ->onQueue('whatsapp-ai')
            ->delay(now()->addSeconds(2));

        Log::info('WhatsApp: mensaje ingestado', [
            'wamid' => $this->messageId,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp: ingesta falló definitivamente', [
            'wamid' => $this->messageId,
            'waId' => $this->waId,
            'error' => $exception->getMessage(),
        ]);
    }
}
