<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyClientQuoteReady implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $quoteId,
    ) {
        $this->onConnection('database_ai');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("inbox:{$this->conversationId}"))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            Log::warning('NotifyClientQuoteReady: conversación no encontrada', ['conversation_id' => $this->conversationId]);

            return;
        }

        if ($conversation->aiState()['quote_ready']) {
            Log::info('NotifyClientQuoteReady: cotización ya enviada, saliendo', ['conversation_id' => $this->conversationId]);

            return;
        }

        // Si el cliente revirtió etapa (revert_to_stage) mientras esta cotización
        // estaba en vuelo, quedó 'expired' — no tiene sentido presentarla.
        $quote = Quote::find($this->quoteId);
        if (! $quote || $quote->status === 'expired') {
            Log::info('NotifyClientQuoteReady: quote inexistente o expirada, saliendo', [
                'conversation_id' => $this->conversationId,
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        // Destinatario: se envía por BSUID (recipient); si tenemos el teléfono del cliente,
        // tiene precedencia (formato `to`, sin '+'). Ver WhatsAppOutboundService::recipientPayload.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->recipientPhone();
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! $phoneNumberId) {
            Log::error('NotifyClientQuoteReady: destinatario o phoneNumberId no disponibles', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $trigger = 'Las cotizaciones ya están listas. '
            ."Usá tu herramienta para obtener la cotización número {$this->quoteId} "
            .'y presentá todas las alternativas disponibles al cliente ahora.';

        $reply = $orchestrator->handle($trigger, $conversation);

        SendWhatsAppMessage::dispatch($phone, $bsuid, $reply['text'], $phoneNumberId, $this->conversationId, $reply['agent'], null, $reply['buttons'] ?? null)
            ->onQueue('whatsapp-outbound');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('NotifyClientQuoteReady: Job falló definitivamente', [
            'conversation_id' => $this->conversationId,
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
