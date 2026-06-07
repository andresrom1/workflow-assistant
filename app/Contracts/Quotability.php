<?php

namespace App\Contracts;

use App\Models\Vehicle;
use App\Services\Quotability\QuotabilityResult;

/**
 * Puerto agnóstico: "¿este auto es cotizable por algún proveedor?".
 *
 * La capa de canal (identify-vehicle) depende de ESTA interface, no de Visred.
 * Recibe hechos de dominio (`Vehicle`) y devuelve un tri-estado agnóstico
 * ({@see QuotabilityResult}). Toda la mecánica de catálogo/token/desambiguación
 * vive detrás del implementador concreto (p.ej. VisredQuotabilityResolver).
 *
 * Frontera dura: NUNCA lo llama el NLU (VehicleIdentifierAgent/Tool/Service).
 * Lo dispara el canal. Ver RESOLVER-DESIGN.md §1/§7/§8 y CLAUDE.md §desacople.
 */
interface Quotability
{
    /**
     * Resuelve el auto contra el/los proveedor(es) y, si resuelve, refina
     * `Vehicle.version` (hecho de dominio) como efecto. NO habla con el cliente.
     */
    public function check(Vehicle $vehicle): QuotabilityResult;
}
