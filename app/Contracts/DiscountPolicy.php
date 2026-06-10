<?php

namespace App\Contracts;

use App\Services\MaxDiscountPolicy;

/**
 * Política de selección de descuento — agnóstica de proveedor.
 *
 * Recibe la lista neutra de descuentos disponibles de una compañía
 * (`{ref, percent}`) y decide cuál aplicar. La obtención del catálogo (Visred)
 * vive en el adapter; ESTA decisión es negocio puro y swappable (Strategy).
 * Impl. por defecto: {@see MaxDiscountPolicy}.
 */
interface DiscountPolicy
{
    /**
     * @param  list<array{ref: string, percent: float}>  $discounts  Catálogo neutro de la compañía.
     * @param  float  $capPercent  Tope del productor para esa compañía (no expuesto por Visred → config).
     * @return array{ref: string, percent: float}|null El elegido, o null si no aplica.
     */
    public function choose(array $discounts, float $capPercent): ?array;
}
