<?php

namespace App\Services\Reports;

use App\Enums\PolizaEstado;
use App\Enums\ReporteOrigen;
use Illuminate\Http\UploadedFile;

/**
 * Parser de un reporte de cartera. Cada origen ({@see ReporteOrigen}) tiene su
 * propia implementación porque el layout (columnas, vocabulario de estados) es distinto.
 *
 * Devuelve filas neutras (arrays escalares JSON-safe, sin DTO), agnósticas del dominio: el
 * import decide qué hacer con ellas. El estado del reporte se entrega crudo (`estado_origen`)
 * y ya mapeado a {@see PolizaEstado} (`estado_mapeado`, value o null).
 */
interface PolicyReportParser
{
    /**
     * @return list<array{
     *     asegurado: string|null,
     *     documento: string|null,
     *     numero: string|null,
     *     company: string|null,
     *     producto: string|null,
     *     ramo: string|null,
     *     patente: string|null,
     *     telefono: string|null,
     *     email: string|null,
     *     estado_origen: string|null,
     *     estado_mapeado: string|null,
     *     vigencia: string|null
     * }>
     */
    public function parse(UploadedFile $file): array;
}
