<?php

namespace App\Services\Afip;

use RuntimeException;

/**
 * AFIP procesó el pedido y RECHAZÓ el comprobante. El mensaje trae el texto de los
 * `Errors`/`Observaciones` que devuelve WSFEv1, para persistirlo en `invoices.observaciones`.
 *
 * Implica que hubo respuesta: el número no se consumió y queda libre para el comprobante
 * siguiente. Para el caso en que no se sabe qué pasó, ver
 * {@see AfipRespuestaIndeterminadaException} — la distinción es la que evita marcar como
 * rechazada una factura que en realidad quedó emitida.
 */
class AfipEmisionException extends RuntimeException {}
