<?php

namespace App\Enums;

use App\Services\PolicyReportImportService;

/**
 * Qué haría una fila de un reporte de cartera al materializar el lote, calculado por el
 * dry-run del import ({@see PolicyReportImportService}) sin escribir nada.
 *
 * - `Create`: no existe una `Poliza` con ese `company+numero` → se crea la cadena.
 * - `UpdateEstado`: existe y el reporte trae un estado/vigencia distinto → se actualiza.
 * - `Noop`: existe y no hay cambios → no se toca.
 * - `Exception`: la fila no es procesable (sin documento/numero, o rompería la invariante
 *   "una vigente por Risk") → se salta y se reporta.
 */
enum ReporteRowAccion: string
{
    case Create = 'create';
    case UpdateEstado = 'update_estado';
    case Noop = 'noop';
    case Exception = 'exception';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Alta',
            self::UpdateEstado => 'Actualiza estado',
            self::Noop => 'Sin cambios',
            self::Exception => 'Excepción',
        };
    }
}
