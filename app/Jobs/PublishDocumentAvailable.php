<?php

namespace App\Jobs;

use App\Services\DocumentAvailablePublisher;
use App\Services\PolicyDocumentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publica el push "documento nuevo disponible" al topic de una cuenta de la app.
 *
 * Encolado desde {@see PolicyDocumentNotifier} cuando se carga un
 * documento de una póliza vigente. Best-effort: si FCM o las credenciales fallan, se
 * loguea y se descarta — un push perdido no debe romper nada del flujo de carga.
 */
class PublishDocumentAvailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Un push a FCM. */
    public int $timeout = 30;

    public function __construct(
        public readonly int $mobileAccountId,
        public readonly int $polizaId,
        public readonly string $kind,
    ) {
        // Push a la app mobile: no es una respuesta conversacional.
        $this->onQueue('background');
    }

    public function handle(DocumentAvailablePublisher $publisher): void
    {
        try {
            $publisher->publishToAccount($this->mobileAccountId, [
                'poliza_id' => (string) $this->polizaId,
                'kind' => $this->kind,
            ]);
        } catch (Throwable $e) {
            Log::warning('PublishDocumentAvailable: no se pudo publicar el push de documento nuevo', [
                'mobile_account_id' => $this->mobileAccountId,
                'poliza_id' => $this->polizaId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
