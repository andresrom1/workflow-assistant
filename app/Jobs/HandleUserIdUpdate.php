<?php

namespace App\Jobs;

use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleUserIdUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** Un UPDATE sobre `conversations`. */
    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload  El objeto user_id_updates[0] del webhook de Meta.
     *                                         Contiene 'previous' y 'current' con sus respectivos 'user_id'.
     */
    public function __construct(private readonly array $payload)
    {
        $this->onQueue('default');
    }

    /**
     * Actualiza el ext_user_id de la conversación cuando un usuario de WhatsApp
     * migra de número de teléfono (lo que regenera su BSUID).
     *
     * Meta envía este evento en entry[].changes[].value.user_id_updates[].
     */
    public function handle(): void
    {
        $previousId = data_get($this->payload, 'previous.user_id');
        $currentId = data_get($this->payload, 'current.user_id');

        if (! $previousId || ! $currentId || $previousId === $currentId) {
            return;
        }

        $updated = Conversation::where('ext_user_id', $previousId)
            ->update(['ext_user_id' => $currentId]);

        Log::info('WhatsApp: BSUID actualizado por migración de número', [
            'previous_ext_user_id' => $previousId,
            'current_ext_user_id' => $currentId,
            'conversations_updated' => $updated,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp: HandleUserIdUpdate falló', [
            'payload' => $this->payload,
            'error' => $exception->getMessage(),
        ]);
    }
}
