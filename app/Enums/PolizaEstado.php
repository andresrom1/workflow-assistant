<?php

namespace App\Enums;

/**
 * Estado de una póliza respecto a su vigencia.
 *
 * Un Risk puede tener varias pólizas a la vez: una `vigente` + una `emitida`
 * (renovación ya emitida pero todavía no vigente, disponible para revisión
 * los días previos al vencimiento) + N `vencida` históricas.
 *
 * Ver spec v2 §5 "Notas de diseño de la API" → vehículo↔póliza es 1:N temporal.
 */
enum PolizaEstado: string
{
    case Vigente = 'vigente';
    case Emitida = 'emitida';
    case Vencida = 'vencida';
    case Anulada = 'anulada';

    // Estados que sólo provienen de un reporte de cartera (no de la emisión ni la ingesta
    // de PDFs). `NoVigente` es el paraguas del reporte; `Vencida` es un subcaso, pero el
    // reporte no lo distingue, así que del import nunca sale `Vencida`. Ninguno es activo,
    // por lo que caen fuera de las colas de mantenimiento/vencimientos.
    case Programada = 'programada';
    case EnProceso = 'en_proceso';
    case NoVigente = 'no_vigente';

    /**
     * Estados que puede fijar el admin al confirmar una ingesta de PDFs. Excluye los que
     * sólo provienen de un reporte de cartera ({@see self::Programada}, {@see self::EnProceso},
     * {@see self::NoVigente}).
     *
     * @return list<self>
     */
    public static function paraIngesta(): array
    {
        return [self::Vigente, self::Emitida, self::Vencida, self::Anulada];
    }

    public function label(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Emitida => 'Emitida',
            self::Vencida => 'Vencida',
            self::Anulada => 'Anulada',
            self::Programada => 'Programada',
            self::EnProceso => 'En proceso',
            self::NoVigente => 'No vigente',
        };
    }
}
