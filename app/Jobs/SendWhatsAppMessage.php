<?php

namespace App\Jobs;

use App\Exceptions\WhatsAppSpamLimitException;
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
    ) {}

    public function handle(WhatsAppOutboundService $waService): void
    {
        try {
            $waService->sendMessage($this->waId, $this->text, $this->phoneNumberId, $this->conversationId);
        } catch (WhatsAppSpamLimitException $e) {
            $this->fail($e);
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
