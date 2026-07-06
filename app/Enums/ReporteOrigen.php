<?php

namespace App\Enums;

use App\Services\Reports\ReportParserFactory;

/**
 * Origen de un reporte de cartera (snapshot de pólizas) que se sube al panel para
 * mantener la cartera al día.
 *
 * Puede haber varios orígenes con layouts distintos (hoy sólo el portal del productor;
 * mañana el reporte propio de una compañía u otra fuente). El origen es el **seam** que
 * elige el parser correcto ({@see ReportParserFactory}) y queda como
 * provenance en `polizas.metadata.origen_reporte` — NUNCA en una clave de dominio. Los
 * orígenes no colisionan: convergen sobre la misma `Poliza` por `company+numero`.
 */
enum ReporteOrigen: string
{
    case PortalVisred = 'portal_visred';

    public function label(): string
    {
        return match ($this) {
            self::PortalVisred => 'Portal Visred',
        };
    }
}
