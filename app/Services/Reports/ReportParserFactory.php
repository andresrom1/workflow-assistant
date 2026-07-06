<?php

namespace App\Services\Reports;

use App\Enums\ReporteOrigen;

/**
 * Resuelve el parser correcto para un origen de reporte. Agregar un origen nuevo es una
 * línea acá + su parser — sin tocar a los demás (los orígenes no colisionan).
 */
class ReportParserFactory
{
    public function for(ReporteOrigen $origen): PolicyReportParser
    {
        return match ($origen) {
            ReporteOrigen::PortalVisred => new PortalVisredReportParser,
        };
    }
}
