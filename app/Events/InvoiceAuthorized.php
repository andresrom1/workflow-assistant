<?php

namespace App\Events;

use App\Listeners\GenerateInvoicePdf;
use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AFIP autorizó un comprobante (con CAE). Lo escucha {@see GenerateInvoicePdf}
 * para generar el PDF, desacoplando esa responsabilidad de la emisión.
 */
class InvoiceAuthorized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
    ) {}
}
