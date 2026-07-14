<?php

namespace App\Enums;

/**
 * Estado de un documento estacionado por el ingestor local, en la cola de Pendientes.
 *
 * Modo de arranque (doc v3/04 §5): nada se materializa en firme al ingestar. Una fila
 * arranca `EnExtraccion` (esperando/corriendo el job de extracción LLM, v2), pasa a
 * `Pendiente` si el documento pertenece al corpus de pólizas, o a `DescartadoAuto` si el
 * extractor lo clasifica como no-póliza (factura, cotización, denuncia, etc. — nunca se
 * materializa nada). Al confirmar el admin un `Pendiente` se materializa la cadena y pasa
 * a `Confirmado`; si el admin lo rechaza, `Descartado`. Reversible solo manual→automático
 * cuando el extractor demuestre acierto consistente.
 */
enum IngestaStatus: string
{
    case EnExtraccion = 'en_extraccion';
    case Pendiente = 'pendiente';
    case Confirmado = 'confirmado';
    case Descartado = 'descartado';
    case DescartadoAuto = 'descartado_auto';

    public function label(): string
    {
        return match ($this) {
            self::EnExtraccion => 'En extracción',
            self::Pendiente => 'Pendiente',
            self::Confirmado => 'Confirmado',
            self::Descartado => 'Descartado',
            self::DescartadoAuto => 'Descartado (auto)',
        };
    }
}
