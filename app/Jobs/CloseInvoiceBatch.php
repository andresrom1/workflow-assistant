<?php

namespace App\Jobs;

use App\Enums\InvoiceEstado;
use App\Models\InvoiceBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cierra un lote de facturación: fija el estado, la hora de fin y los conteos que lee la UI.
 *
 * Vive en un job propio —último eslabón de la cadena de {@see EmitInvoice}— porque el cierre es lo
 * que destraba el módulo. Cuando el cierre estaba al final de un loop, un crash a mitad de camino
 * lo salteaba y el lote quedaba `processing` para siempre, bloqueando el formulario de lotes
 * nuevos. Ver ROADMAP, bitácora 2026-08-04.
 *
 * El `catch()` de la cadena lo invoca con `estado = 'failed'` cuando algún comprobante agota sus
 * reintentos, así que el lote siempre termina en un estado terminal.
 */
class CloseInvoiceBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Cierre de lote: sólo escribe estado en la base. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $batchId,
        public readonly string $estado = 'completed',
    ) {
        // Cierre de lote de facturación.
        $this->onQueue('background');
    }

    public function handle(): void
    {
        $batch = InvoiceBatch::find($this->batchId);

        if ($batch === null || $batch->finished_at !== null) {
            return;
        }

        $invoices = $batch->invoices();

        $batch->update([
            'estado' => $this->estado,
            'finished_at' => now(),
            'summary' => [
                'autorizadas' => (clone $invoices)->where('estado', InvoiceEstado::Authorized)->count(),
                'rechazadas' => (clone $invoices)->where('estado', InvoiceEstado::Rejected)->count(),
                'pendientes' => (clone $invoices)->where('estado', InvoiceEstado::Pending)->count(),
                'total' => $invoices->count(),
            ],
        ]);
    }
}
