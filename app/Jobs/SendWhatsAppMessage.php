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

    /**
     * @param  list<array{id: string, title: string}>|null  $buttons
     */
    public function __construct(
        private readonly ?string $phone,
        private readonly ?string $bsuid,
        private readonly string $text,
        private readonly string $phoneNumberId,
        private readonly ?int $conversationId = null,
        private readonly ?string $agentName = null,
        private readonly ?int $executionLogId = null,
        private readonly ?array $buttons = null,
    ) {}

    public function handle(
        WhatsAppOutboundService $waService,
        MessageModalityDecider $decider,
        TextToSpeechService $tts,
        MediaStorageService $storage,
    ): void {
        // Always send typing indicator before any response.
        $waService->sendTypingIndicator($this->phone, $this->bsuid, $this->phoneNumberId);

        // Los botones fuerzan texto: sin modality decider ni TTS. WhatsApp renderiza
        // toda burbuja interactiva con un ancho fijo angosto, así que el cuerpo largo
        // de la recomendación se manda como texto full-width y solo la pregunta de
        // cierre (último párrafo) acompaña a los botones en la burbuja compacta.
        if ($this->buttons !== null && $this->buttons !== []) {
            [$body, $caption] = $this->splitForButtons($this->text);

            // Sin párrafo separable y demasiado largo para un interactivo: se degrada
            // a texto plano (con log), como red de seguridad histórica.
            if ($body === null && mb_strlen($caption) > 1024) {
                Log::warning('WhatsApp: body > 1024 con botones pendientes — enviando texto plano', [
                    'conversationId' => $this->conversationId,
                    'length' => mb_strlen($this->text),
                ]);
            } else {
                if ($body !== null) {
                    $waService->sendMessage(
                        $this->phone,
                        $this->bsuid,
                        $body,
                        $this->phoneNumberId,
                        $this->conversationId,
                        $this->agentName,
                        false,
                        config('ai.default'),
                    );
                }

                $waService->sendInteractiveButtons(
                    $this->phone,
                    $this->bsuid,
                    $caption,
                    $this->buttons,
                    $this->phoneNumberId,
                    $this->conversationId,
                    $this->agentName,
                    config('ai.default'),
                );
                $this->linkLatestOutboundMessage();

                return;
            }
        }

        $conversation = $this->conversationId
            ? Conversation::find($this->conversationId)
            : null;

        $decision = $conversation
            ? $decider->decide($this->text, $this->agentName ?? '', $conversation)
            : ['modality' => Modality::Text, 'eligible' => false, 'reason' => 'no_conversation'];

        Log::info('WhatsApp: modality decision', [
            'phone' => $this->phone,
            'bsuid' => $this->bsuid,
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
                'phone' => $this->phone,
                'bsuid' => $this->bsuid,
                'conversationId' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendAsText($waService, $decision);
    }

    /**
     * Separa el texto del agente en cuerpo (full-width) + caption (burbuja de botones).
     * El caption es el último párrafo (la pregunta de cierre); el cuerpo es todo lo
     * anterior. Split de presentación pura: sin `\n\n` o con alguna mitad vacía, no se
     * separa y el texto entero queda como caption.
     *
     * @return array{0: string|null, 1: string} [body|null, caption]
     */
    private function splitForButtons(string $text): array
    {
        $pos = mb_strrpos($text, "\n\n");
        if ($pos === false) {
            return [null, $text];
        }

        $body = trim(mb_substr($text, 0, $pos));
        $caption = trim(mb_substr($text, $pos + 2));

        return ($body === '' || $caption === '') ? [null, $text] : [$body, $caption];
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
            $this->phone,
            $this->bsuid,
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
                $this->phone,
                $this->bsuid,
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
            'phone' => $this->phone,
            'bsuid' => $this->bsuid,
            'conversationId' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
