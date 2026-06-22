<?php

namespace App\Jobs;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\MessageAttachment;
use App\Services\Media\MediaStorageService;
use App\Services\Media\SpeechToTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        private readonly int $attachmentId,
        private readonly int $conversationId,
        private readonly ?string $waId,
        private readonly string $phoneNumberId,
    ) {
        $this->onConnection('database_media');
    }

    public function handle(
        WhatsAppAdapter $adapter,
        MediaStorageService $storageService,
        SpeechToTextService $sttService,
    ): void {
        $attachment = MessageAttachment::with('message')->findOrFail($this->attachmentId);

        $attachment->update(['processing_status' => 'processing']);

        try {
            $content = $adapter->downloadMedia($attachment->external_media_id);

            $stored = $storageService->store($content, $attachment->attachment_type, $attachment->mime_type ?? 'audio/ogg');

            $transcript = $sttService->transcribe($stored->path);

            $attachment->update([
                'storage_path' => $stored->path,
                'storage_url' => $stored->url,
                'file_size' => $stored->size,
                'transcription' => $transcript,
                'processing_status' => 'done',
                'processed_at' => now(),
            ]);

            $attachment->message->update(['content' => $transcript]);

            ProcessConversationInbox::dispatch($this->conversationId, $this->waId, $this->phoneNumberId)
                ->onQueue('whatsapp-ai')
                ->delay(now()->addSeconds(2));
        } catch (\Throwable $e) {
            $attachment->update([
                'processing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('ProcessMediaAttachment: failed', [
                'attachment_id' => $this->attachmentId,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMediaAttachment: job failed definitively', [
            'attachment_id' => $this->attachmentId,
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
