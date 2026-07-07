<?php

namespace App\Jobs;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
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
        private readonly ?string $waId,
        private readonly string $messageBody,
        private readonly string $messageId,
        private readonly string $phoneNumberId,
        private readonly string $contactName,
        private readonly string $messageType = 'text',
        private readonly ?string $extUserId = null,
        private readonly ?string $extUsername = null,
        private readonly ?string $mediaId = null,
        private readonly ?string $mediaMimeType = null,
    ) {}

    public function handle(ConversationRepository $conversationRepo): void
    {
        // 1. Idempotencia — evita procesar el mismo mensaje dos veces si Meta reenvía el webhook.
        $cacheKey = 'processed_wamid_'.$this->messageId;
        if (Cache::has($cacheKey)) {
            Log::info('WhatsApp: mensaje duplicado ignorado', ['wamid' => $this->messageId]);

            return;
        }

        $type = MessageType::tryFrom($this->messageType) ?? MessageType::Text;

        // 2. Ignorar tipos no soportados aún.
        if (! in_array($type, [MessageType::Text, MessageType::Audio, MessageType::Interactive], true)) {
            Cache::put($cacheKey, true, now()->addDay());

            return;
        }

        // 3. Guardar mensajes de texto (o taps de botón, que llegan como texto) vacíos
        //    como ingestados pero sin procesar.
        if ($type !== MessageType::Audio && (trim($this->messageBody) === '' || $this->messageBody === '0')) {
            Cache::put($cacheKey, true, now()->addDay());

            return;
        }

        // 4. Obtener o crear la conversación — algoritmo dual-key:
        //    Primero por ext_user_id (BSUID, estable aunque el usuario cambie de número),
        //    luego fallback al wa_id (teléfono).
        $conversation = $this->extUserId
            ? $conversationRepo->findByExtUserId($this->extUserId)
            : null;

        if ($conversation instanceof Conversation) {
            // Si el usuario migró de número, actualizar el teléfono de la conversación (solo si llegó uno).
            if ($this->waId && $conversation->external_conversation_id !== $this->waId) {
                $conversation->update(['external_conversation_id' => $this->waId]);
            }
        } else {
            // Clave de creación: el teléfono si llegó; si no, el BSUID (siempre presente desde abril 2026).
            $externalId = $this->waId ?? $this->extUserId;

            if (! $externalId) {
                Log::warning('WhatsApp: mensaje sin wa_id ni user_id, se ignora', ['wamid' => $this->messageId]);

                return;
            }

            $conversation = $conversationRepo->findOrCreateByExternalId($externalId, 'whatsapp');
        }

        // Guardar ext_user_id y ext_username si aún no están (sin sobreescribir valores existentes).
        $conversation->updateExternalIdentifiers($this->extUserId, $this->extUsername);

        // 4b. Persistir el nombre del contacto en el Customer recurrente (customer_id ya vinculado).
        if ($this->contactName && $conversation->customer_id) {
            $conversation->load('customer');
            if ($conversation->customer && ! $conversation->customer->name) {
                $conversation->customer->update(['name' => $this->contactName]);
            }
        }

        // 4c. Persistir mensaje entrante (firstOrCreate evita duplicados por retry de Meta).
        //     processed_at queda null — el inbox processor lo marcará al procesarlo.
        $message = Message::firstOrCreate(
            ['external_message_id' => $this->messageId],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $type,
                'content' => $type !== MessageType::Audio ? $this->messageBody : null,
                'sender_name' => $this->contactName,
                'sender_phone' => $this->waId,
            ]
        );

        // 5. Marcar wamid como ingestado para evitar doble ingesta si Meta reenvía.
        Cache::put($cacheKey, true, now()->addDay());

        if ($type === MessageType::Audio) {
            // 6a. Crear el attachment y despachar transcripción.
            $attachment = MessageAttachment::firstOrCreate(
                ['message_id' => $message->id],
                [
                    'attachment_type' => 'audio',
                    'external_media_id' => $this->mediaId,
                    'mime_type' => $this->mediaMimeType,
                    'processing_status' => 'pending',
                ]
            );

            ProcessMediaAttachment::dispatch(
                $attachment->id,
                $conversation->id,
                $this->waId,
                $this->phoneNumberId,
            )->onQueue('media');

            Log::info('WhatsApp: audio ingestado, transcripción encolada', [
                'wamid' => $this->messageId,
                'conversation_id' => $conversation->id,
                'attachment_id' => $attachment->id,
            ]);
        } else {
            // 6b. Texto: despachar el inbox processor con la ventana de debounce.
            ProcessConversationInbox::dispatch($conversation->id, $this->waId, $this->phoneNumberId)
                ->onQueue('whatsapp-ai')
                ->delay(now()->addSeconds((int) config('whatsapp.inbox_debounce_seconds', 8)));

            Log::info('WhatsApp: mensaje ingestado', [
                'wamid' => $this->messageId,
                'conversation_id' => $conversation->id,
            ]);
        }
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
