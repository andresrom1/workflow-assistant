<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Repositories\ConversationRepository;
use App\Services\WhatsApp\WhatsAppOutboundService;
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
    ) {}

    public function handle(
        InsuranceOrchestrator $orchestrator,
        WhatsAppOutboundService $waService,
        ConversationRepository $conversationRepo,
    ): void {
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

        // 3. Obtener o crear la conversación (el estado del flujo vive en metadata.ai_state).
        $conversation = $conversationRepo->findOrCreateByExternalId($this->waId, 'whatsapp');

        // 4. El orquestador elige el sub-agente correcto y devuelve la respuesta del LLM.
        $reply = $orchestrator->handle($this->messageBody, $conversation);

        // 5. Enviar respuesta al usuario por WhatsApp.
        $waService->sendMessage($this->waId, $reply, $this->phoneNumberId);

        // 6. Marcar el mensaje como procesado para evitar duplicados.
        Cache::put($cacheKey, true, now()->addDay());

        Log::info('WhatsApp: mensaje procesado', [
            'wamid' => $this->messageId,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp: Job falló definitivamente', [
            'wamid' => $this->messageId,
            'waId' => $this->waId,
            'error' => $exception->getMessage(),
        ]);
    }
}
