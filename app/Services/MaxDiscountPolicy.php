<?php

namespace App\Services;

use App\Contracts\DiscountPolicy;

/**
 * Elige el MAYOR descuento disponible que no supere el tope del productor.
 *
 * Hallazgo live (D8, 2026-06-08): el catálogo `/params/discount/` lista descuentos
 * que **superan el máximo permitido para el productor** (Triunfo: catálogo hasta 30%,
 * pero emitir con >20% devuelve 400 "supera el máximo permitido (20%)"). Ese tope NO
 * lo expone Visred → es config nuestra (`visred.max_discount_percent`). Default 0 =
 * sin bonificar (siempre válido); subir el tope da competitividad sin romper la emisión.
 *
 * Decisión de negocio del productor MANGO; agnóstica de proveedor. El tope por
 * compañía lo inyecta el llamador (el adapter lo lee de config). Swappable.
 */
class MaxDiscountPolicy implements DiscountPolicy
{
    public function choose(array $discounts, float $capPercent): ?array
    {
        $best = null;
        foreach ($discounts as $discount) {
            if ($discount['percent'] > $capPercent) {
                continue; // respeta el tope del productor (no expuesto por el catálogo)
            }
            if ($best === null || $discount['percent'] > $best['percent']) {
                $best = $discount;
            }
        }

        return $best;
    }
}
