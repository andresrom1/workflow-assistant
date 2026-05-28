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
}
