<?php

namespace App\Jobs;

use App\Enums\InvoiceEstado;
use App\Models\InvoiceBatch;
use App\Services\Afip\AfipSoapService;
use App\Services\InvoicePdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite un lote de Facturas C contra AFIP, una por una. Si AFIP rechaza una, la marca
 * `Rejected` con el error y CONTINÚA con la siguiente (no corta el lote). El PDF de cada
 * factura autorizada se genera al vuelo al descargarla (ver {@see InvoicePdfService}),
 * no acá.
 *
 * Serializado por punto de venta ({@see WithoutOverlapping}): la numeración correlativa de AFIP
 * no tolera dos emisiones en paralelo sobre el mismo pto de venta.
 */
class EmitInvoiceBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly int $batchId,
        public readonly int $ptoVta,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping("afip-ptovta:{$this->ptoVta}")];
    }

    public function handle(AfipSoapService $afip): void
    {
        $batch = InvoiceBatch::find($this->batchId);
        if ($batch === null) {
            return;
        }

        $pendientes = $batch->invoices()
            ->where('estado', InvoiceEstado::Pending)
            ->orderBy('id')
            ->get();

        if ($pendientes->isEmpty()) {
            $this->cerrar($batch);

            return;
        }

        $tipoCbte = (int) config('afip.tipo_comprobante');
        $ultimo = $afip->ultimoAutorizado($this->ptoVta, $tipoCbte);
        $offset = 1;

        foreach ($pendientes as $invoice) {
            $numero = $ultimo + $offset;

            try {
                $result = $afip->autorizar($invoice, $numero);

                $invoice->update([
                    'numero_comprobante' => $result['numero'],
                    'cae' => $result['cae'],
                    'cae_vencimiento' => Carbon::createFromFormat('Ymd', $result['cae_vencimiento']),
                    'estado' => InvoiceEstado::Authorized,
                ]);

                $offset++; // el número recién se consume si AFIP lo autorizó
            } catch (Throwable $e) {
                $invoice->update([
                    'estado' => InvoiceEstado::Rejected,
                    'observaciones' => $e->getMessage(),
                ]);

                Log::warning('EmitInvoiceBatch: factura rechazada', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->cerrar($batch);
    }

    private function cerrar(InvoiceBatch $batch): void
    {
        $invoices = $batch->invoices();

        $batch->update([
            'estado' => 'completed',
            'finished_at' => now(),
            'summary' => [
                'autorizadas' => (clone $invoices)->where('estado', InvoiceEstado::Authorized)->count(),
                'rechazadas' => (clone $invoices)->where('estado', InvoiceEstado::Rejected)->count(),
                'total' => $invoices->count(),
            ],
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('EmitInvoiceBatch: el lote falló antes de completar', [
            'batch_id' => $this->batchId,
            'error' => $e->getMessage(),
        ]);
    }
}
