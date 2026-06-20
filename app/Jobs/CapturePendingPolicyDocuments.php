<?php

namespace App\Jobs;

use App\Contracts\EmissionProvider;
use App\Models\Poliza;
use App\Models\PolizaProviderRef;
use App\Services\PolicyDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Captura DIFERIDA de los documentos oficiales que no estuvieron listos al emitir.
 *
 * La compañía genera los PDFs de forma asíncrona: recién emitida la póliza algunos
 * todavía se están generando, así que `emit()` los reporta como pendientes y el dominio
 * persiste una `poliza_provider_refs` (token opaco + `kind` faltantes). Este job re-pide
 * esos documentos al proveedor (vía el puerto agnóstico {@see EmissionProvider}), persiste
 * los que ya estén listos y descuenta los capturados de la referencia.
 *
 * Cadencia: 1 intento por minuto durante ~10 minutos (delay inicial + backoff de 60s,
 * 10 tries). Se re-encola con delay mientras queden pendientes y el `presale_id` siga
 * vivo — no bloquea un worker esperando. Agotada la ventana (o caducado el presale),
 * borra la referencia y deja los faltantes para la carga manual del admin. El
 * `presale_id` nunca aparece acá: el dominio sólo maneja el token opaco de la referencia.
 */
class CapturePendingPolicyDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public readonly int $polizaId) {}

    public function backoff(): int
    {
        return (int) config('visred.document_retry_backoff', 60);
    }

    public function handle(EmissionProvider $emissionProvider, PolicyDocumentService $policyDocuments): void
    {
        $poliza = Poliza::query()->with('providerRef')->find($this->polizaId);
        $ref = $poliza?->providerRef;

        // Ya no hay nada que capturar (otro intento lo cerró, o nunca hubo pendientes).
        if ($poliza === null || $ref === null || $ref->pending_document_kinds === []) {
            $ref?->delete();

            return;
        }

        $ref->update(['last_attempted_at' => now()]);

        $documents = $emissionProvider->capturePendingDocuments(
            $ref->document_token,
            $ref->product_id,
            $ref->pending_document_kinds,
        );

        if ($documents !== []) {
            $policyDocuments->storeFromEmission($poliza, $documents);
        }

        $captured = array_map(static fn (array $document): string => (string) $document['kind'], $documents);
        $remaining = array_values(array_diff($ref->pending_document_kinds, $captured));

        if ($remaining === []) {
            $ref->delete(); // Todo capturado: la referencia efímera cumplió su función.

            return;
        }

        $ref->update(['pending_document_kinds' => $remaining]);

        // Quedan pendientes: re-encolar con backoff para reintentar (la compañía puede
        // tardar en generar el PDF). Al superar $tries, la queue dispara failed().
        $this->release($this->backoff());
    }

    /**
     * Reintentos agotados (o presale caducado): se abandona la captura automática. Los
     * documentos faltantes quedan para la carga manual del admin; la referencia opaca se
     * borra (el presale ya no sirve).
     */
    public function failed(\Throwable $e): void
    {
        $ref = PolizaProviderRef::query()->where('poliza_id', $this->polizaId)->first();

        Log::warning('CapturePendingPolicyDocuments: reintentos agotados — documentos para carga manual', [
            'poliza_id' => $this->polizaId,
            'pending' => $ref?->pending_document_kinds,
            'error' => $e->getMessage(),
        ]);

        $ref?->delete();
    }
}
