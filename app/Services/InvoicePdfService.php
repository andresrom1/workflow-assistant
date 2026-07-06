<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Afip\AfipSoapService;
use App\Services\Facturacion\Emisor;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el PDF reglamentario de una Factura C ya autorizada (dompdf) con el QR de AFIP
 * (RG 4892) embebido, lo guarda en el disco `local` bajo `invoices/{YYYY-MM}/` y devuelve el
 * path relativo (que queda en `invoices.pdf_path`).
 */
class InvoicePdfService
{
    public function __construct(
        private readonly AfipSoapService $afip,
        private readonly Emisor $emisor,
    ) {}

    public function generar(Invoice $invoice): string
    {
        $invoice->loadMissing('company');

        // SvgWriter (no requiere ext-gd); dompdf renderiza el SVG vía php-svg-lib.
        $qrDataUri = (new Builder(
            writer: new SvgWriter,
            data: $this->afip->qrUrl($invoice),
            size: 220,
            margin: 4,
        ))->build()->getDataUri();

        $pdf = Pdf::loadView('invoices.factura-c', [
            'invoice' => $invoice,
            'emisor' => $this->emisor->toArray(),
            'qr' => $qrDataUri,
        ]);

        $numero = str_pad((string) $invoice->numero_comprobante, 8, '0', STR_PAD_LEFT);
        $path = sprintf('invoices/%s/factura-c-%d-%s.pdf', $invoice->fecha_comprobante->format('Y-m'), $invoice->pto_vta, $numero);

        Storage::disk('local')->put($path, $pdf->output());
        $invoice->update(['pdf_path' => $path]);

        return $path;
    }
}
