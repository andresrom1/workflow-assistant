<?php

namespace App\Services\Quotability;

/**
 * Resultado agnóstico de "matchear" el auto contra los proveedores.
 * Es lo único que cruza a la capa de canal — sin token, sin nombre de proveedor.
 */
enum QuotabilityStatus: string
{
    /** Algún proveedor puede cotizar este auto: avanzá y prometé la cotización. */
    case Quotable = 'quotable';

    /** Falta un hecho de dominio para desambiguar (p.ej. transmisión): preguntá. */
    case NeedsFact = 'needs_fact';

    /** Ningún proveedor lo cotiza automáticamente: rama honesta, sin promesa rota. */
    case NotQuotable = 'not_quotable';
}
