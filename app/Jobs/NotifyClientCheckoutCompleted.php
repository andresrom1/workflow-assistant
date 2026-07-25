<?php

namespace App\Jobs;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyClientCheckoutCompleted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        private readonly int $quoteId,
    ) {
        $this->onConnection('database_ai');
    }

    /**
     * Envía un agradecimiento fijo por WhatsApp al completar el checkout. No pasa
     * por el orquestador/LLM: el texto es siempre el mismo y no depende del estado
     * de la conversación. La documentación de la póliza llega en un mensaje aparte
     * (SendPolicyDocumentsToClient) cuando la emisión la captura — puede llegar
     * antes o después de este mensaje, por eso el texto no asume un orden.
     */
    public function handle(): void
    {
        $quote = Quote::with('conversation')->find($this->quoteId);

        if (! $quote || ! $quote->conversation) {
            Log::info('NotifyClientCheckoutCompleted: sin conversación de origen, saliendo', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        $conversation = $quote->conversation;

        // Destinatario: se envía por BSUID (recipient); el teléfono del cliente, si lo tenemos,
        // tiene precedencia (formato `to`, sin '+'). Ver WhatsAppOutboundService::recipientPayload.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->recipientPhone();
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! $phoneNumberId) {
            Log::error('NotifyClientCheckoutCompleted: destinatario o phoneNumberId no disponibles', [
                'quote_id' => $this->quoteId,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $text = '¡Gracias por confiar en MANGO! Recibimos todos tus datos y estamos gestionando la emisión de tu póliza con la compañía. '
            .'Te confirmamos por acá apenas esté lista, junto con la documentación. '
            .'Cualquier duda, escribime por acá.';

        SendWhatsAppMessage::dispatch($phone, $bsuid, $text, $phoneNumberId, $conversation->id)
            ->onQueue('whatsapp-outbound');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('NotifyClientCheckoutCompleted: Job falló definitivamente', [
            'quote_id' => $this->quoteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
