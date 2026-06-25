<?php

namespace App\Enums;

/**
 * Estado de un documento estacionado por el ingestor local, en la cola de Pendientes.
 *
 * Modo de arranque (doc v3/04 §5): nada se materializa en firme al ingestar. Una fila
 * arranca `Pendiente`; al confirmar el admin se materializa la cadena y pasa a
 * `Confirmado`; si el admin lo rechaza, `Descartado`. Reversible solo manual→automático
 * cuando el parser demuestre acierto consistente.
 */
enum IngestaStatus: string
{
    case Pendiente = 'pendiente';
    case Confirmado = 'confirmado';
    case Descartado = 'descartado';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmado => 'Confirmado',
            self::Descartado => 'Descartado',
        };
    }
}
