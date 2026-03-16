<?php

namespace App\Jobs;

use App\Models\CheckoutSession;
use App\Models\Quote;
use App\Services\PolizaEmisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job que despacha la solicitud de emisión de póliza a la API externa.
 *
 * Se encola inmediatamente luego de que el checkout es confirmado.
 * Si la API no está disponible, reintenta con backoff exponencial.
 *
 * Cuando la API esté implementada, solo hay que completar PolizaEmisionService.
 */
class EmitirPoliza implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 120; // segundos entre reintentos

    public function __construct(
        public readonly int $quoteId,
        public readonly int $checkoutSessionId,
    ) {}

    public function handle(PolizaEmisionService $service): void
    {
        $quote = Quote::findOrFail($this->quoteId);
        $session = CheckoutSession::findOrFail($this->checkoutSessionId);

        Log::info('EmitirPoliza: iniciando emisión', [
            'quote_id' => $quote->id,
            'checkout_session_id' => $session->id,
        ]);

        try {
            $result = $service->emitir($quote, $session);

            Log::info('EmitirPoliza: emisión exitosa', [
                'quote_id' => $quote->id,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('EmitirPoliza: falló la emisión', [
                'quote_id' => $quote->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e; // Relanzar para habilitar reintentos en la queue
        }
    }

    /**
     * Acciones al agotar todos los reintentos.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('EmitirPoliza: reintentos agotados — requiere intervención manual', [
            'quote_id' => $this->quoteId,
            'checkout_session_id' => $this->checkoutSessionId,
            'error' => $e->getMessage(),
        ]);

        // TODO: Notificar al equipo interno (Slack, mail, etc.)
    }
}
