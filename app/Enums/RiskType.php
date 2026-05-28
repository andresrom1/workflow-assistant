<?php

namespace App\Enums;

/**
 * Tipo de bien asegurado.
 *
 * Modelo STI: una sola tabla `risks` con `type` enum + `metadata` JSONB para
 * atributos type-specific. Extensible sin migraciones al sumar tipos nuevos
 * (moto, hogar, accidentes personales, vida, etc.).
 */
enum RiskType: string
{
    case Vehicle = 'vehicle';
}
