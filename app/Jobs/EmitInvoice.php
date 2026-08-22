<?php

namespace App\Jobs;

use App\Enums\InvoiceEstado;
use App\Http\Controllers\Admin\InvoiceBatchController;
use App\Models\Invoice;
use App\Services\Afip\AfipEmisionException;
use App\Services\Afip\AfipSoapService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Emite UNA Factura C contra AFIP. Se despacha una por comprobante y encadenada
 * ({@see InvoiceBatchController::store()}) para que la numeración
 * correlativa quede serializada y para que cada job dure ~10s — muy por debajo de cualquier
 * timeout de worker.
 *
 * El corazón del diseño es reservar el número ANTES de llamar a AFIP. La llamada a
 * `FECAESolicitar` no es idempotente por sí sola, pero el número de comprobante SÍ funciona como
 * clave de idempotencia: AFIP rechaza un número ya autorizado en vez de duplicarlo. Persistir la
 * reserva primero es lo que permite, después de un crash, preguntar por ESE comprobante en lugar
 * de asumir hacia adelante.
 *
 * Sin eso, el 2026-08-04 el proceso murió entre `autorizar()` y el `update()` del CAE: AFIP tenía
 * el comprobante 18 emitido, la base no se enteró, y al reanudar se le asignó el 19 a la misma
 * factura. Resultado: un cliente facturado dos veces. Ver ROADMAP, bitácora 2026-08-04.
 *
 * Regla que ordena todo el manejo de errores: ante una emisión en duda **nunca se adivina**. Ni se
 * marca autorizada, ni se reintenta a ciegas. Se pregunta, o se deja para el próximo intento.
 */
class EmitInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    /**
     * Por debajo del `retry_after` de la conexión `database` (200s), para que la cola no reclame
     * el job mientras todavía corre — que es la falla que arrancó el incidente del 2026-08-04.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly int $invoiceId,
        public readonly int $ptoVta,
    ) {
        // Facturación contra AFIP: el cliente no la ve en tiempo real.
        $this->onQueue('background');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // `expireAfter` es obligatorio: sin él, un worker matado deja el lock del punto de venta
        // tomado 24h (el default de DatabaseLock) y bloquea toda la facturación.
        return [
            (new WithoutOverlapping("afip-ptovta:{$this->ptoVta}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(AfipSoapService $afip): void
    {
        $invoice = Invoice::find($this->invoiceId);

        // Ya resuelta: reintento del job o reanudación de un lote parcialmente emitido.
        if ($invoice === null || $invoice->estado !== InvoiceEstado::Pending) {
            return;
        }

        if ($invoice->numero_reservado !== null) {
            if ($this->reconciliar($afip, $invoice)) {
                return;
            }
        } else {
            $numero = $afip->ultimoAutorizado($this->ptoVta, $invoice->tipo_comprobante) + 1;
            $invoice->update(['numero_reservado' => $numero]);
        }

        $this->emitir($afip, $invoice);
    }

    /**
     * Resuelve una emisión en duda: la factura tiene número reservado pero sigue `Pending`, así que
     * no sabemos si el intento anterior llegó a autorizarla.
     *
     * @return bool `true` si quedó resuelta (no hay que emitir), `false` si el número está libre y
     *              hay que emitir con ese mismo número.
     */
    private function reconciliar(AfipSoapService $afip, Invoice $invoice): bool
    {
        $numero = (int) $invoice->numero_reservado;

        // Si esto lanza, la excepción sube a propósito: la factura queda Pending con su reserva
        // intacta y el próximo intento vuelve a preguntar. Adivinar acá es lo que duplica facturas.
        $existente = $afip->consultar($this->ptoVta, $invoice->tipo_comprobante, $numero);

        if ($existente === null) {
            return false; // AFIP no lo tiene: el número sigue libre.
        }

        if (! $this->correspondeA($invoice, $existente)) {
            $invoice->update([
                'estado' => InvoiceEstado::Rejected,
                'observaciones' => "El comprobante {$numero} ya existe en AFIP con otro receptor o importe "
                    ."(CUIT {$existente['doc_nro']}, importe {$existente['imp_total']}). "
                    .'Requiere revisión manual: no se emitió nada para no pisar un comprobante ajeno.',
            ]);

            Log::error('EmitInvoice: el número reservado pertenece a otro comprobante', [
                'invoice_id' => $invoice->id,
                'numero' => $numero,
                'afip' => $existente,
            ]);

            return true;
        }

        $invoice->update([
            'numero_comprobante' => $existente['numero'],
            'cae' => $existente['cae'],
            'cae_vencimiento' => Carbon::createFromFormat('Ymd', $existente['cae_vencimiento']),
            'estado' => InvoiceEstado::Authorized,
        ]);

        Log::warning('EmitInvoice: emisión en duda reconciliada, el comprobante ya estaba en AFIP', [
            'invoice_id' => $invoice->id,
            'numero' => $numero,
            'cae' => $existente['cae'],
        ]);

        return true;
    }

    /**
     * ¿El comprobante que AFIP tiene con ese número es realmente esta factura? Se compara contra el
     * snapshot del receptor antes de adoptar un CAE ajeno como propio.
     *
     * @param  array{numero: int, cae: string, cae_vencimiento: string, doc_nro: string, imp_total: string, resultado: string}  $existente
     */
    private function correspondeA(Invoice $invoice, array $existente): bool
    {
        return $existente['resultado'] === 'A'
            && $existente['doc_nro'] === $invoice->receptor_cuit
            && number_format((float) $existente['imp_total'], 2, '.', '') === number_format((float) $invoice->importe, 2, '.', '');
    }

    private function emitir(AfipSoapService $afip, Invoice $invoice): void
    {
        $numero = (int) $invoice->numero_reservado;

        try {
            $result = $afip->autorizar($invoice, $numero);
        } catch (AfipEmisionException $e) {
            // Solo se captura el rechazo: AFIP contestó, así que el número NO se consumió y se
            // libera para el comprobante siguiente. Todo lo demás —incluida
            // AfipRespuestaIndeterminadaException y los errores de red— se deja propagar para que
            // el reintento reconcilie en vez de marcar como rechazada una factura ya emitida.
            $invoice->update([
                'estado' => InvoiceEstado::Rejected,
                'observaciones' => $e->getMessage(),
                'numero_reservado' => null,
            ]);

            Log::warning('EmitInvoice: factura rechazada por AFIP', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $invoice->update([
            'numero_comprobante' => $result['numero'],
            'cae' => $result['cae'],
            'cae_vencimiento' => Carbon::createFromFormat('Ymd', $result['cae_vencimiento']),
            'estado' => InvoiceEstado::Authorized,
        ]);
    }
}
