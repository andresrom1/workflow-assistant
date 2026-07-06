<?php

namespace App\Listeners;

use App\Events\InvoiceAuthorized;
use App\Services\InvoicePdfService;

/**
 * Genera el PDF de un comprobante autorizado. Síncrono a propósito: el PDF debe existir antes
 * de que el lote cierre para que quede disponible en la descarga ZIP.
 */
class GenerateInvoicePdf
{
    public function __construct(
        private readonly InvoicePdfService $pdf,
    ) {}

    public function handle(InvoiceAuthorized $event): void
    {
        $this->pdf->generar($event->invoice);
    }
}
