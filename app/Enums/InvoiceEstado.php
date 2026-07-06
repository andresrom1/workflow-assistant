<?php

namespace App\Enums;

use App\Models\Invoice;

/**
 * Estado de un comprobante ({@see Invoice}) frente a AFIP.
 *
 * `Pending` es el estado inicial al crear el lote; el job de emisión lo lleva a `Authorized`
 * (con CAE) o `Rejected` (con el error en `observaciones`). No hay transición de vuelta:
 * un comprobante rechazado se re-factura en un lote nuevo.
 */
enum InvoiceEstado: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Authorized => 'Autorizada',
            self::Rejected => 'Rechazada',
        };
    }
}
