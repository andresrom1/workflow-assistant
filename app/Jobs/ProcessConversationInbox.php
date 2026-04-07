<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessConversationInbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $conversationId,
        private readonly string $waId,
        private readonly string $phoneNumberId,
    ) {
        // Usa la conexión con retry_after extendido (200s) para tolerar llamadas largas al LLM.
        $this->onConnection('database_ai');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("inbox:{$this->conversationId}"))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator): void
    {
        $messages = Message::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->whereNull('processed_at')
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            Log::info('WhatsApp inbox: sin mensajes pendientes', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $conversation = Conversation::findOrFail($this->conversationId);

        $combinedBody = $messages->pluck('content')->implode("\n");

        // Marcar como procesados ANTES de llamar al AI para que un reintento
        // del job no vuelva a llamar al AI con los mismos mensajes.
        Message::whereIn('id', $messages->pluck('id'))
            ->update(['processed_at' => now()]);

        $reply = $orchestrator->handle($combinedBody, $conversation);

        SendWhatsAppMessage::dispatch($this->waId, $reply['text'], $this->phoneNumberId, $this->conversationId, $reply['agent'])
            ->onQueue('whatsapp-outbound');

        $contactName = $messages->first()?->sender_name;

        if ($contactName) {
            $conversation->refresh();

            if ($conversation->customer && ! $conversation->customer->name) {
                $conversation->customer->update(['name' => $contactName]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp inbox: Job falló definitivamente', [
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
