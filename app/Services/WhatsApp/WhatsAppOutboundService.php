<?php

namespace App\Services\WhatsApp;

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
     * Envía un mensaje de texto al número indicado.
     *
     * Solo válido dentro de la ventana de 24 horas posterior al último mensaje del usuario.
     *
     * @param  string  $to  Número en formato E.164 SIN el "+" (ej: "5491112345678")
     * @param  string  $phoneNumberId  ID del número emisor (no el E.164)
     */
    public function sendMessage(string $to, string $text, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null, bool $audioEligible = false, ?string $aiProvider = null): array
    {
        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
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
     * Sends a typing indicator to the recipient.
     *
     * Uses the WhatsApp Cloud API typing indicator feature.
     *
     * @param  string  $to  Recipient wa_id (E.164 without "+")
     * @param  string  $phoneNumberId  Sender phone number ID
     */
    public function sendTypingIndicator(string $to, string $phoneNumberId): void
    {
        try {
            $this->post($phoneNumberId, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'reaction',
                'reaction' => [
                    'message_id' => '',
                    'emoji' => '',
                ],
            ]);
        } catch (\Throwable) {
            // Typing indicator is best-effort — never block the main message send.
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
     * @param  string  $to  Recipient wa_id (E.164 without "+")
     * @param  string  $mediaId  Meta media_id from uploadMedia()
     * @param  string  $phoneNumberId  Sender phone number ID
     */
    public function sendAudioMessage(string $to, string $mediaId, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null, ?string $content = null, ?string $aiProvider = null): ?Message
    {
        $response = $this->post($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
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
