<?php

namespace App\Services\Afip;

use RuntimeException;

/**
 * AFIP rechazó (o no pudo autorizar) un comprobante. El mensaje trae el texto de los
 * `Errors`/`Observaciones` que devuelve WSFEv1, para persistirlo en `invoices.observaciones`.
 */
class AfipEmisionException extends RuntimeException {}
