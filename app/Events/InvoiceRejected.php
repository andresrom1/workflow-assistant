<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AFIP rechazó un comprobante (el motivo queda en `invoices.observaciones`). El lote continúa
 * con la siguiente factura.
 */
class InvoiceRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
    ) {}
}
