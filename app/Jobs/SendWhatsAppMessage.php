<?php

namespace App\Jobs;

use App\Enums\Modality;
use App\Exceptions\WhatsAppSpamLimitException;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Media\MediaStorageService;
use App\Services\Media\TextToSpeechService;
use App\Services\Message\MessageModalityDecider;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(
        private readonly string $waId,
        private readonly string $text,
        private readonly string $phoneNumberId,
        private readonly ?int $conversationId = null,
        private readonly ?string $agentName = null,
        private readonly ?int $executionLogId = null,
    ) {}

    public function handle(
        WhatsAppOutboundService $waService,
        MessageModalityDecider $decider,
        TextToSpeechService $tts,
        MediaStorageService $storage,
    ): void {
        // Always send typing indicator before any response.
        $waService->sendTypingIndicator($this->waId, $this->phoneNumberId);

        $conversation = $this->conversationId
            ? Conversation::find($this->conversationId)
            : null;

        $decision = $conversation
            ? $decider->decide($this->text, $this->agentName ?? '', $conversation)
            : ['modality' => Modality::Text, 'eligible' => false, 'reason' => 'no_conversation'];

        Log::info('WhatsApp: modality decision', [
            'waId' => $this->waId,
            'conversationId' => $this->conversationId,
            'agentName' => $this->agentName,
            'modality' => $decision['modality']->value,
            'eligible' => $decision['eligible'],
            'reason' => $decision['reason'],
            'ratio' => $decision['ratio'] ?? null,
            'p' => $decision['p'] ?? null,
            'window_size' => $decision['window_size'] ?? null,
        ]);

        try {
            if ($decision['modality'] === Modality::Audio) {
                $this->sendAsAudio($waService, $tts, $storage, $decision);

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp: TTS/audio failed, falling back to text', [
                'waId' => $this->waId,
                'conversationId' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendAsText($waService, $decision);
    }

    /**
     * Generate TTS, store in R2, upload to Meta, and send as audio message.
     *
     * @param  array{modality: Modality, eligible: bool, reason: string, ratio: float|null, p: float|null, window_size: int|null}  $decision
     */
    private function sendAsAudio(
        WhatsAppOutboundService $waService,
        TextToSpeechService $tts,
        MediaStorageService $storage,
        array $decision,
    ): void {
        $audio = $tts->generate($this->text);

        $stored = $storage->store($audio['content'], 'audio', $audio['mime_type']);

        $mediaId = $waService->uploadMedia($audio['content'], $audio['mime_type'], $this->phoneNumberId);

        // sendAudioMessage returns the persisted Message (or null) directly.
        $message = $waService->sendAudioMessage(
            $this->waId,
            $mediaId,
            $this->phoneNumberId,
            $this->conversationId,
            $this->agentName,
            $this->text,
            config('ai.default'),
        );

        if ($message) {
            MessageAttachment::create([
                'message_id' => $message->id,
                'attachment_type' => 'audio',
                'mime_type' => $audio['mime_type'],
                'file_size' => strlen($audio['content']),
                'storage_path' => $stored->path,
                'storage_url' => $stored->url,
                'processing_status' => 'done',
                'processed_at' => now(),
            ]);

            $this->linkOutboundMessage($message->id);
        } else {
            $this->linkLatestOutboundMessage();
        }
    }

    /**
     * Send the message as plain text.
     *
     * @param  array{modality: Modality, eligible: bool, reason: string, ratio: float|null, p: float|null, window_size: int|null}  $decision
     */
    private function sendAsText(WhatsAppOutboundService $waService, array $decision): void
    {
        try {
            $waService->sendMessage(
                $this->waId,
                $this->text,
                $this->phoneNumberId,
                $this->conversationId,
                $this->agentName,
                $decision['eligible'],
                config('ai.default'),
            );

            $this->linkLatestOutboundMessage();
        } catch (WhatsAppSpamLimitException $e) {
            $this->fail($e);
        }
    }

    /**
     * Vincula un mensaje outbound específico al log de ejecución del agente.
     */
    private function linkOutboundMessage(int $messageId): void
    {
        if (! $this->executionLogId) {
            return;
        }

        AgentExecutionLog::where('id', $this->executionLogId)
            ->update(['outbound_message_id' => $messageId]);
    }

    /**
     * Busca el último mensaje outbound de la conversación y lo vincula al log.
     * Usado cuando el servicio no retorna el modelo directamente (ej: sendMessage).
     * Es confiable porque ProcessConversationInbox usa WithoutOverlapping por conversación.
     */
    private function linkLatestOutboundMessage(): void
    {
        if (! $this->executionLogId || ! $this->conversationId) {
            return;
        }

        $message = Message::where('conversation_id', $this->conversationId)
            ->where('direction', 'outbound')
            ->latest()
            ->first();

        if ($message) {
            $this->linkOutboundMessage($message->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp: SendWhatsAppMessage falló definitivamente', [
            'waId' => $this->waId,
            'conversationId' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
