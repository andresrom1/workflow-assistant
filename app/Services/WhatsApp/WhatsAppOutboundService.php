<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageType;
use App\Exceptions\WhatsAppSpamLimitException;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppOutboundService
{
    private readonly string $baseUrl;

    private readonly string $accessToken;

    public function __construct()
    {
        $version = config('services.whatsapp.api_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->accessToken = config('services.whatsapp.access_token');
    }

    /**
     * Envía un mensaje de texto al destinatario indicado (teléfono o BSUID).
     *
     * Solo válido dentro de la ventana de 24 horas posterior al último mensaje del usuario.
     *
     * @param  string|null  $phone  Número en formato E.164 SIN el "+" (ej: "5491112345678"), o null
     * @param  string|null  $bsuid  Business-Scoped User ID, usado cuando no hay teléfono
     * @param  string  $phoneNumberId  ID del número emisor (no el E.164)
     */
    public function sendMessage(?string $phone, ?string $bsuid, string $text, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null, bool $audioEligible = false, ?string $aiProvider = null): array
    {
        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            ...$this->recipientPayload($phone, $bsuid),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]);

        if ($conversationId && ! empty($response['messages'])) {
            Message::create([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'agent_name' => $agentName,
                'ai_provider' => $aiProvider,
                'audio_eligible' => $audioEligible,
                'content' => $text,
                'external_message_id' => data_get($response, 'messages.0.id'),
                'sender_phone' => $phoneNumberId,
            ]);
        }

        return $response;
    }

    /**
     * Envía un message template aprobado por Meta.
     *
     * Para avisos iniciados por el negocio fuera de la ventana de 24h (los
     * contactos de emergencia y el PAS no escribieron antes). El template debe
     * existir y estar aprobado en Meta con el mismo nombre/idioma.
     *
     * @param  string  $to  Número en formato E.164 SIN el "+" (ej: "5491112345678")
     * @param  string  $templateName  Nombre del template aprobado
     * @param  string  $language  Locale exacto del template (ej: "es_AR")
     * @param  list<string>  $bodyParams  Variables posicionales del body ({{1}}, {{2}}, …)
     * @param  string  $phoneNumberId  ID del número emisor (no el E.164)
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $bodyParams, string $phoneNumberId): array
    {
        $components = [];
        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $text): array => ['type' => 'text', 'text' => $text],
                    $bodyParams,
                ),
            ];
        }

        return $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    /**
     * Marks an inbound message as read and shows the "typing…" bubble to the customer.
     *
     * Both things happen in the same call: the Cloud API bundles the typing indicator with
     * the read receipt, so it anchors to the INBOUND message id — not to a recipient. Meta
     * holds the bubble for 25 seconds at most, or until we send the reply.
     *
     * Se llama dos veces por mensaje entrante y a propósito: en la INGESTA, que corre en su
     * propia cola y nunca espera nada, y de nuevo al EMPEZAR el turno, que puede arrancar
     * mucho después si el worker de IA está ocupado. La primera da el acuse inmediato; la
     * segunda rearma la burbuja para el tiempo de generación. Repetir el acuse es idempotente.
     *
     * @param  string  $wamid  WhatsApp id of the inbound message being answered
     * @param  string  $phoneNumberId  Sender phone number ID
     */
    public function sendTypingIndicator(string $wamid, string $phoneNumberId): void
    {
        if (! (bool) config('whatsapp.typing_indicator_enabled', true)) {
            return;
        }

        try {
            $this->post($phoneNumberId, [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $wamid,
                'typing_indicator' => ['type' => 'text'],
            ]);
        } catch (\Throwable $e) {
            // Best-effort: never block the reply. But it does get logged — the previous
            // version swallowed the error silently, which is why nobody noticed for months
            // that it was posting an empty reaction Meta rejects.
            Log::warning('WhatsApp: typing indicator falló', [
                'wamid' => $wamid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uploads binary media content to the WhatsApp Cloud API media endpoint.
     *
     * @param  string  $binaryContent  Raw binary content of the file
     * @param  string  $mimeType  MIME type (e.g. "audio/mpeg")
     * @param  string  $phoneNumberId  Sender phone number ID
     * @return string The Meta media_id to use in sendAudioMessage()
     */
    public function uploadMedia(string $binaryContent, string $mimeType, string $phoneNumberId): string
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->attach('file', $binaryContent, 'audio.mp3', ['Content-Type' => $mimeType])
            ->post("{$this->baseUrl}/{$phoneNumberId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('WhatsApp media upload error', [
                'code' => $error['code'] ?? 0,
                'message' => $error['message'] ?? '',
            ]);

            throw new \RuntimeException('WhatsApp media upload failed: '.($error['message'] ?? 'unknown error'));
        }

        return $response->json('id');
    }

    /**
     * Sends an audio message using a previously uploaded media_id.
     *
     * Returns the persisted outbound Message (nullable when no conversationId),
     * so the caller can attach a MessageAttachment without a secondary DB lookup.
     *
     * @param  string|null  $phone  Recipient wa_id (E.164 without "+"), or null
     * @param  string|null  $bsuid  Business-Scoped User ID, used when there is no phone
     * @param  string  $mediaId  Meta media_id from uploadMedia()
     * @param  string  $phoneNumberId  Sender phone number ID
     */
    public function sendAudioMessage(?string $phone, ?string $bsuid, string $mediaId, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null, ?string $content = null, ?string $aiProvider = null): ?Message
    {
        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            ...$this->recipientPayload($phone, $bsuid),
            'type' => 'audio',
            'audio' => [
                'id' => $mediaId,
            ],
        ]);

        if ($conversationId && ! empty($response['messages'])) {
            return Message::create([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'type' => 'audio',
                'content' => $content,
                'agent_name' => $agentName,
                'ai_provider' => $aiProvider,
                'audio_eligible' => true,
                'external_message_id' => data_get($response, 'messages.0.id'),
                'sender_phone' => $phoneNumberId,
            ]);
        }

        return null;
    }

    /**
     * Envía un mensaje interactivo con botones de respuesta rápida (máx. 3, título
     * máx. 20 caracteres — límites de la Cloud API). Solo válido dentro de la
     * ventana de 24h.
     *
     * @param  list<array{id: string, title: string}>  $buttons
     * @return array<string, mixed>
     */
    public function sendInteractiveButtons(?string $phone, ?string $bsuid, string $bodyText, array $buttons, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null, ?string $aiProvider = null): array
    {
        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            ...$this->recipientPayload($phone, $bsuid),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($bodyText, 0, 1024)],
                'action' => [
                    'buttons' => array_map(
                        static fn (array $b): array => [
                            'type' => 'reply',
                            'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)],
                        ],
                        array_slice($buttons, 0, 3),
                    ),
                ],
            ],
        ]);

        if ($conversationId && ! empty($response['messages'])) {
            Message::create([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'type' => MessageType::Interactive,
                'agent_name' => $agentName,
                'ai_provider' => $aiProvider,
                'content' => $bodyText,
                'external_message_id' => data_get($response, 'messages.0.id'),
                'sender_phone' => $phoneNumberId,
            ]);
        }

        return $response;
    }

    /**
     * Envía un documento (PDF) por link público. Solo válido dentro de la ventana de 24h.
     *
     * El link debe ser una URL pública accesible por Meta (ej: storage_url de R2)
     * — no requiere upload previo vía uploadMedia().
     *
     * @param  string|null  $phone  Recipient wa_id (E.164 without "+"), or null
     * @param  string|null  $bsuid  Business-Scoped User ID, used when there is no phone
     * @param  string  $link  URL pública del PDF
     * @param  string  $filename  Nombre de archivo mostrado al destinatario
     * @param  string  $phoneNumberId  Sender phone number ID
     * @return array<string, mixed>
     */
    public function sendDocumentMessage(?string $phone, ?string $bsuid, string $link, string $filename, string $phoneNumberId, ?string $caption = null, ?int $conversationId = null, ?string $agentName = null, ?string $aiProvider = null): array
    {
        $document = ['link' => $link, 'filename' => $filename];
        if ($caption !== null) {
            $document['caption'] = $caption;
        }

        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            ...$this->recipientPayload($phone, $bsuid),
            'type' => 'document',
            'document' => $document,
        ]);

        if ($conversationId && ! empty($response['messages'])) {
            Message::create([
                'conversation_id' => $conversationId,
                'direction' => 'outbound',
                'type' => MessageType::Document,
                'agent_name' => $agentName,
                'ai_provider' => $aiProvider,
                'content' => $caption ?? $filename,
                'external_message_id' => data_get($response, 'messages.0.id'),
                'sender_phone' => $phoneNumberId,
            ]);
        }

        return $response;
    }

    /**
     * Decide el campo de destinatario del payload de Meta: `to` con el teléfono cuando está
     * disponible, o `recipient` con el BSUID cuando no hay teléfono (doc §10). El teléfono tiene
     * prioridad; el BSUID es la alternativa para usuarios sin número visible.
     *
     * @return array<string, string>
     */
    private function recipientPayload(?string $phone, ?string $bsuid): array
    {
        return ($phone !== null && $phone !== '')
            ? ['to' => $phone]
            : ['recipient' => (string) $bsuid];
    }

    private function post(string $phoneNumberId, array $payload): array
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(10)
            ->retry(3, 200)
            ->post("{$this->baseUrl}/{$phoneNumberId}/messages", $payload);

        if ($response->failed()) {
            $error = $response->json('error', []);
            $code = $error['code'] ?? 0;

            Log::error('WhatsApp API error', [
                'code' => $code,
                'message' => $error['message'] ?? '',
            ]);

            $this->handleApiError($code);
        }

        return $response->json();
    }

    /**
     * Manejo de errores críticos de la API.
     *
     * El error 131048 (SPAM rate limit) requiere detener difusión inmediatamente.
     * Para cualquier otro error el Job reintentará automáticamente.
     */
    private function handleApiError(int $code): void
    {
        if ($code === 131048) {
            throw new WhatsAppSpamLimitException(
                'WhatsApp Quality Rating comprometido (131048). Detener difusión inmediatamente.'
            );
        }
    }
}
