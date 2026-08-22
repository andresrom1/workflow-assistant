<?php

namespace App\AI\Probes;

/**
 * El resumen mínimo de una serie de corridas.
 *
 * Se reporta el tramo y no el promedio porque con estas distribuciones el promedio miente: dos
 * corridas idénticas del mismo modelo, mismo prompt y mismo contexto dieron 34 s y 175 s. Lo que
 * hay que ver es la dispersión.
 */
class ProbeStats
{
    /**
     * `min · p50 · max`, dividiendo por `$divisor` para pasar de ms a segundos cuando hace falta.
     *
     * @param  list<int>  $valores
     */
    public static function tramo(array $valores, int $divisor = 1, string $sufijo = ''): string
    {
        if ($valores === []) {
            return '(sin datos)';
        }

        sort($valores);

        $fmt = fn (float $v): string => $divisor > 1
            ? number_format($v / $divisor, 1, ',', '.').$sufijo
            : number_format($v, 0, ',', '.').$sufijo;

        return 'min '.$fmt((float) $valores[0])
            .' · p50 '.$fmt((float) $valores[intdiv(count($valores) - 1, 2)])
            .' · max '.$fmt((float) $valores[count($valores) - 1]);
    }
}
