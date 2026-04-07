<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
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

        $waId = $conversation->external_conversation_id;
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if (! $waId || ! $phoneNumberId) {
            Log::error('NotifyClientQuoteReady: waId o phoneNumberId no disponibles', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $trigger = 'Las cotizaciones ya están listas. '
            ."Usá tu herramienta para obtener la cotización número {$this->quoteId} "
            .'y presentá todas las alternativas disponibles al cliente ahora.';

        $reply = $orchestrator->handle($trigger, $conversation);

        SendWhatsAppMessage::dispatch($waId, $reply['text'], $phoneNumberId, $this->conversationId, $reply['agent'])
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
