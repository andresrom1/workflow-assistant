<?php

namespace App\Services\Afip;

use App\Jobs\EmitInvoice;
use RuntimeException;

/**
 * No se pudo determinar qué hizo AFIP con el pedido: respuesta ilegible o SOAP Fault. A diferencia
 * de {@see AfipEmisionException}, esto NO es un rechazo — el comprobante puede haber quedado
 * autorizado del lado de AFIP.
 *
 * Quien la reciba no debe adivinar: {@see EmitInvoice} la deja propagar para que el
 * reintento consulte el número reservado y averigüe la verdad.
 */
class AfipRespuestaIndeterminadaException extends RuntimeException {}
