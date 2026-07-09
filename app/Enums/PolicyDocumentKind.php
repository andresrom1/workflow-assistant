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
    case Certificado = 'certificado';
    case Endoso = 'endoso';
    case Cupon = 'cupon';
    case CirculationCard = 'circulation-card';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Poliza => 'Póliza',
            self::Certificado => 'Certificado de cobertura',
            self::Endoso => 'Endoso',
            self::Cupon => 'Cupón de pago',
            self::CirculationCard => 'Cédula de circulación',
            self::Otro => 'Otro',
        };
    }

    /**
     * Documentos obligatorios para que una póliza vigente se considere "completa"
     * (checklist de mantenimiento). El Certificado de cobertura es deseable pero no
     * obligatorio, por eso no integra este set. Supuesto de dominio a confirmar: hoy
     * es un set fijo, no varía por compañía/producto.
     *
     * @return list<self>
     */
    public static function expectedForActivePolicy(): array
    {
        return [self::Poliza, self::CirculationCard];
    }
}
