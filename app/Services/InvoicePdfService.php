<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Afip\AfipSoapService;
use App\Services\Facturacion\Emisor;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Genera el PDF reglamentario de una Factura C ya autorizada (dompdf) con el QR de AFIP
 * (RG 4892) embebido. Al vuelo, SIN persistir: el contenido es 100% derivable de la `Invoice`
 * (snapshot inmutable del receptor + CAE) y de la config del emisor, así que no hace falta
 * guardar el archivo — se regenera igual cada vez que se pide (descarga individual o ZIP).
 */
class InvoicePdfService
{
    public function __construct(
        private readonly AfipSoapService $afip,
        private readonly Emisor $emisor,
    ) {}

    /**
     * Devuelve los bytes del PDF (3 copias: original/duplicado/triplicado).
     */
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

        return Pdf::loadView('invoices.factura-c', [
            'invoice' => $invoice,
            'emisor' => $this->emisor->toArray(),
            'qr' => $qrDataUri,
        ])
            // El paquete trae el subsetting de fuentes deshabilitado por default: sin esto,
            // dompdf embebe la tipografía DejaVu completa en cada PDF (~900KB en vez de ~30KB).
            ->setOption('enable_font_subsetting', true)
            ->output();
    }

    /**
     * Nombre de archivo reglamentario: FC-{punto de venta}-{número de comprobante}.pdf
     */
    public function filename(Invoice $invoice): string
    {
        $numero = str_pad((string) $invoice->numero_comprobante, 8, '0', STR_PAD_LEFT);

        return sprintf('FC-%d-%s.pdf', $invoice->pto_vta, $numero);
    }
}
