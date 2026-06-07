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

/**
 * Envía un message template aprobado a un único destinatario.
 *
 * Separado de SendWhatsAppMessage (ese hace TTS/modalidad/conversación, acá no
 * aplica): los avisos de emergencia/siniestro son notificaciones planas fuera
 * de la ventana de 24h. Reintenta ante errores transitorios; el 131048 (spam)
 * frena sin reintentar.
 */
class SendWhatsAppTemplate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    /**
     * @param  string  $waId  Destinatario en E.164 SIN el "+"
     * @param  list<string>  $bodyParams  Variables posicionales del body del template
     */
    public function __construct(
        private readonly string $waId,
        private readonly string $templateName,
        private readonly string $language,
        private readonly array $bodyParams,
        private readonly string $phoneNumberId,
    ) {}

    public function handle(WhatsAppOutboundService $waService): void
    {
        try {
            $waService->sendTemplate(
                $this->waId,
                $this->templateName,
                $this->language,
                $this->bodyParams,
                $this->phoneNumberId,
            );
        } catch (WhatsAppSpamLimitException $e) {
            // Meta ya rechazó por spam: reintentar solo empeora el rating.
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp: SendWhatsAppTemplate falló definitivamente', [
            'template' => $this->templateName,
            'error' => $exception->getMessage(),
        ]);
    }
}
