<?php

namespace App\Enums;

/**
 * Tipo de documento oficial de una póliza.
 *
 * Vocabulario estable referenciado desde la captura de emisión (Visred), la carga
 * manual del admin y la lectura de mango-mobile. El valor `poliza` es el que produce
 * hoy la emisión (`config('visred.document_task_types')`); el resto entra por carga
 * manual post-emisión (renovaciones/endosos/correcciones).
 */
enum PolicyDocumentKind: string
{
    case Poliza = 'poliza';
    case Endoso = 'endoso';
    case Cupon = 'cupon';
    case CirculationCard = 'circulation-card';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Poliza => 'Póliza',
            self::Endoso => 'Endoso',
            self::Cupon => 'Cupón de pago',
            self::CirculationCard => 'Cédula de circulación',
            self::Otro => 'Otro',
        };
    }
}
