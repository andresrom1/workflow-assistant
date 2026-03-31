<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\UpdateMessageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verificación del webhook (handshake inicial con Meta).
     *
     * Meta envía un GET con hub.mode, hub.verify_token y hub.challenge.
     * Debemos responder con el challenge en texto plano si el verify_token coincide.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            // Responder con el challenge como texto plano — no JSON, no HTML.
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Recibe los mensajes entrantes de WhatsApp.
     *
     * Valida la firma HMAC-SHA256, extrae el mensaje y despacha el Job.
     * Responde 200 OK inmediatamente (< 250ms) para cumplir con los requisitos de Meta.
     * Si no se responde a tiempo, Meta aplica backoff exponencial por hasta 7 días.
     */
    public function handleIncoming(Request $request): Response
    {
        // 1. Validar la firma HMAC-SHA256 usando el raw body.
        //    Se usa hash_equals() (tiempo constante) para prevenir timing attacks.
        $rawPayload = file_get_contents('php://input');
        $expectedSignature = 'sha256='.hash_hmac('sha256', $rawPayload, config('services.whatsapp.app_secret'));
        $receivedSignature = $request->header('X-Hub-Signature-256', '');

        if (! hash_equals($expectedSignature, $receivedSignature)) {
            return response('Unauthorized', 401);
        }

        $entry = data_get($request->json()->all(), 'entry.0.changes.0.value', []);

        // 2. Mensajes entrantes → despachar Job asíncrono.
        if (! empty($entry['messages'])) {
            $message = $entry['messages'][0];
            $contact = $entry['contacts'][0] ?? [];
            $metadata = $entry['metadata'] ?? [];
            $waId = data_get($contact, 'wa_id');
            $messageId = data_get($message, 'id');
            $phoneNumberId = data_get($metadata, 'phone_number_id');

            // Ignorar mensajes del mismo número emisor (evitar bucles).
            if ($waId && $messageId && $waId !== $phoneNumberId) {
                ProcessWhatsAppMessage::dispatch(
                    $waId,
                    data_get($message, 'text.body', ''),
                    $messageId,
                    $phoneNumberId,
                    data_get($contact, 'profile.name', 'Usuario'),
                    data_get($message, 'type', 'text'),
                );
            }
        }

        // 3. Actualizaciones de estado (delivered, read, failed).
        if (! empty($entry['statuses'])) {
            UpdateMessageStatus::dispatch($entry['statuses'][0]);
        }

        // 4. Responder 200 OK inmediatamente.
        return response('', 200);
    }
}
