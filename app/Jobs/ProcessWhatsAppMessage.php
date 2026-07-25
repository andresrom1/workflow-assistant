<?php

namespace App\Jobs;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
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

    public function handle(ConversationRepository $conversationRepo, CustomerRepository $customerRepo): void
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

        // 4. Obtener o crear la conversación anclada en el BSUID (identidad estable del canal).
        //    Fallback defensivo por teléfono si no llegó BSUID (no debería pasar desde abr-2026).
        $conversation = match (true) {
            $this->extUserId !== null => $conversationRepo->findOrCreateByExtUserId($this->extUserId, 'whatsapp'),
            $this->waId !== null => $conversationRepo->findOrCreateByExternalId($this->waId, 'whatsapp'),
            default => null,
        };

        if (! $conversation instanceof Conversation) {
            Log::warning('WhatsApp: mensaje sin wa_id ni user_id, se ignora', ['wamid' => $this->messageId]);

            return;
        }

        // Guardar ext_user_id y ext_username si aún no están (sin sobreescribir valores existentes).
        $conversation->updateExternalIdentifiers($this->extUserId, $this->extUsername);

        // 4b. Customer del primer mensaje: capturar teléfono/nombre del webhook como ATRIBUTOS
        //     (no identidad — el DNI/email es el "quién", y no se deduplica por teléfono). El
        //     BSUID vive en la conversación. Ver docs del modelo de identidad.
        $this->captureCustomerAttributes($conversation, $customerRepo);

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

    /**
     * Materializa/enriquece el Customer de la conversación con los atributos del webhook.
     * En el primer mensaje crea el Customer (aunque sea anónimo — solo BSUID); en los
     * siguientes completa nombre/teléfono vacíos sin pisar. No deduplica por teléfono: la
     * unificación de identidad es solo por DNI/email (la resuelve el flujo del agente).
     */
    private function captureCustomerAttributes(Conversation $conversation, CustomerRepository $customerRepo): void
    {
        $name = $this->realContactName();

        if (! $conversation->customer_id) {
            $customer = $customerRepo->create(array_filter([
                'phone' => $this->waId,
                'name' => $name,
            ]));
            $conversation->update(['customer_id' => $customer->id]);

            return;
        }

        $conversation->loadMissing('customer');
        $customer = $conversation->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        $updates = array_filter([
            'name' => $name && ! $customer->name ? $name : null,
            'phone' => $this->waId && ! $customer->phone ? $this->waId : null,
        ]);

        if ($updates !== []) {
            $customerRepo->update($customer, $updates);
        }
    }

    /**
     * Nombre real del contacto, o null si es el placeholder 'Usuario' (fallback del webhook
     * cuando el perfil no expone nombre) o viene vacío.
     */
    private function realContactName(): ?string
    {
        $name = trim($this->contactName);

        return ($name === '' || $name === 'Usuario') ? null : $name;
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
