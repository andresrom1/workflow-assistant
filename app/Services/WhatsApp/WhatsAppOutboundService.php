<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsAppSpamLimitException;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppOutboundService
{
    private string $baseUrl;

    private string $accessToken;

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
    public function sendMessage(string $to, string $text, string $phoneNumberId, ?int $conversationId = null, ?string $agentName = null): array
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
                'content' => $text,
                'external_message_id' => data_get($response, 'messages.0.id'),
                'sender_phone' => $phoneNumberId,
            ]);
        }

        return $response;
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
