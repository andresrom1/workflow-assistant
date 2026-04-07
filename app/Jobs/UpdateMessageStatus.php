<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateMessageStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $statusData) {}

    public function handle(): void
    {
        $wamid = $this->statusData['id'] ?? null;
        $newStatus = $this->statusData['status'] ?? null;

        if (! $wamid || ! $newStatus) {
            return;
        }

        Log::info('WhatsApp: actualización de estado recibida', [
            'wamid' => $wamid,
            'status' => $newStatus,
        ]);

        // Aquí se puede agregar la lógica para actualizar el estado en base de datos
        // si se implementa una tabla de mensajes salientes (OutboundMessage).
        // La jerarquía STATUS_HIERARCHY debe usarse para evitar retrocesos de estado.
        //
        // Ejemplo:
        // $message = OutboundMessage::where('wamid', $wamid)->first();
        // if ($message) {
        //     $currentRank = self::STATUS_HIERARCHY[$message->status] ?? 0;
        //     $newRank     = self::STATUS_HIERARCHY[$newStatus] ?? 0;
        //     if ($newRank > $currentRank) {
        //         $message->update(['status' => $newStatus]);
        //     }
        // }
    }
}
