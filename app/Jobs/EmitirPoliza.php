<?php

namespace App\Jobs;

use App\Exceptions\Visred\VisredApiException;
use App\Mail\EmisionFallidaMail;
use App\Models\CheckoutSession;
use App\Models\Quote;
use App\Services\PolizaEmisionService;
use App\Services\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job que despacha la solicitud de emisión de póliza a la API externa.
 *
 * Se encola inmediatamente luego de que el checkout es confirmado. Si la API no
 * está disponible, reintenta con backoff exponencial. Un `validation_error` (400)
 * es determinístico — el mismo request se va a rechazar siempre — así que no
 * reintenta: falla de una para no demorar 10 minutos el aviso.
 */
class EmitirPoliza implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 120; // segundos entre reintentos

    /**
     * Emisión contra Visred: un POST con `VISRED_TIMEOUT` (30s) más los reintentos internos del
     * cliente HTTP. Hasta la Fase 1 del refactor de colas heredaba los 60s del `--timeout` del
     * worker `default` — un valor que nadie eligió para este job. Queda por debajo del
     * `retry_after` de la conexión `database` (200s).
     */
    public int $timeout = 120;

    public function __construct(
        public readonly int $quoteId,
        public readonly int $checkoutSessionId,
    ) {
        // Post-checkout y asíncrona: el cliente ya recibió el aviso. En `default` bloqueaba hasta
        // 2 min el acuse de lectura del próximo mensaje entrante.
        $this->onQueue('background');
    }

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

        } catch (\Throwable $e) {
            Log::error('EmitirPoliza: falló la emisión', [
                'quote_id' => $quote->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                ...$this->visredErrorContext($e),
            ]);

            if ($e instanceof VisredApiException && $e->status() === 400) {
                $this->fail($e);

                return;
            }

            throw $e; // Relanzar para habilitar reintentos en la queue
        }
    }

    /**
     * Reintentos agotados (o 400 determinístico, ver `handle()`): la emisión no se
     * pudo completar. Avisa primero al EQUIPO — casi siempre un humano puede
     * destrabarla — y después al CLIENTE por WhatsApp, sin pasar por el LLM. Deja
     * el quote consultable como fallido en vez de indistinguible de "en vuelo".
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('EmitirPoliza: emisión fallida — requiere intervención manual', [
            'quote_id' => $this->quoteId,
            'checkout_session_id' => $this->checkoutSessionId,
            'error' => $e->getMessage(),
            ...$this->visredErrorContext($e),
        ]);

        $quote = Quote::find($this->quoteId);
        $session = CheckoutSession::find($this->checkoutSessionId);

        if (! $quote || ! $session) {
            return;
        }

        // La póliza SÍ se emitió y lo que falló fue algo posterior (persistir
        // documentos, encolar el reintento). No pisar el estado ni decirle al
        // cliente que falló algo que en realidad tiene emitido.
        if ($quote->status === 'poliza_emitida') {
            Log::warning('EmitirPoliza: failed() sobre un quote ya emitido — no se avisa al cliente', [
                'quote_id' => $this->quoteId,
            ]);

            return;
        }

        $quote->update(['status' => 'emision_fallida']);

        $recipient = app(SettingsService::class)->get('checkout.notifications_email');
        if (! is_string($recipient) || trim($recipient) === '') {
            $recipient = config('mail.checkout_notifications_to', config('mail.from.address'));
        }

        Mail::to($recipient)->queue(new EmisionFallidaMail($quote, $session, [
            'message' => $e->getMessage(),
            ...$this->visredErrorContext($e),
        ]));

        NotifyClientEmissionFailed::dispatch($quote->id);
    }

    /**
     * @return array{status: int|null, error_code: string|null, field_errors: array<string, list<string>>|null}
     */
    private function visredErrorContext(\Throwable $e): array
    {
        return [
            'status' => $e instanceof VisredApiException ? $e->status() : null,
            'error_code' => $e instanceof VisredApiException ? $e->errorCode() : null,
            'field_errors' => $e instanceof VisredApiException ? $e->fieldErrors() : null,
        ];
    }
}
