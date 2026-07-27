<?php

namespace App\Support;

/**
 * Franquicia de una alternativa de cotización, extraída del título del producto.
 *
 * La franquicia es lo único que distingue a las variantes de una misma compañía dentro de un
 * grado de cobertura, pero **no es un campo del modelo**: el proveedor la manda adentro de
 * `quote_alternatives.titulo` como texto libre, con un formato distinto por compañía:
 *
 * - `Todo Riesgo Franquicia 4%`                        → 4%
 * - `Todo Riesgo XL - Franquicia 5%`                   → 5%
 * - `Todo Riesgo Franquicia 7,5% suma asegurada`       → 7,5%
 * - `Todo Riesgo 8% Suma Aseg, Franquicia`             → 8%  (el % va ANTES del marcador)
 * - `D3 - Todo Riesgo Franq 10% - Min $ 400.000`       → 10% con mínimo de $400.000
 *
 * Cuando el título no permite deducirla, los tres campos vuelven `null`: no se inventa un
 * porcentaje. Ver `clave()` para la contrapartida importante de eso en el dedupe.
 */
final class Franquicia
{
    /** Palabra que ancla la franquicia en el título ("Franquicia", "Franq"). */
    private const MARCADOR = '/franq/iu';

    /** Porcentaje con decimal opcional, coma o punto: `4%`, `7,5%`, `10 %`. */
    private const PORCENTAJE = '/(\d+(?:[.,]\d+)?)\s*%/u';

    /**
     * Mínimo en pesos. El `\b` evita que "min" adentro de otra palabra dispare (Administración),
     * y el `$` capturado aparte permite descartar un "Franquicia Mínima 5%", donde el número que
     * sigue a "mínima" es el porcentaje y no un monto. Ver `buscarMinimo()`.
     */
    private const MINIMO = '/\bm[íi]n(?:imo|ima)?\.?\s*(\$?)\s*([\d.,]+)/iu';

    /**
     * `porcentaje` viene en formato local (coma decimal), `minimo` en dígitos crudos y `texto`
     * listo para mostrarle al cliente.
     *
     * @return array{porcentaje: ?string, minimo: ?string, texto: ?string}
     */
    public static function extraer(?string $titulo): array
    {
        $vacio = ['porcentaje' => null, 'minimo' => null, 'texto' => null];

        $normalizado = self::normalizarTitulo($titulo);
        if ($normalizado === null) {
            return $vacio;
        }

        $porcentaje = self::buscarPorcentaje($normalizado);
        if ($porcentaje === null) {
            return $vacio;
        }

        $minimo = self::buscarMinimo($normalizado);

        $texto = "{$porcentaje}% de la suma asegurada";
        if ($minimo !== null) {
            $texto .= ', mínimo $'.number_format((float) $minimo, 0, ',', '.');
        }

        return ['porcentaje' => $porcentaje, 'minimo' => $minimo, 'texto' => $texto];
    }

    /**
     * Clave estable para agrupar variantes equivalentes de una misma compañía.
     *
     * Si el título no se pudo parsear, la clave cae al título normalizado en vez de a un valor
     * compartido. Es deliberado: dos títulos distintos que no se entienden tienen que producir
     * claves distintas, porque quien consume esto se queda con la más barata de cada clave — y
     * colapsar productos genuinamente diferentes borraría opciones en silencio.
     */
    public static function clave(?string $titulo): string
    {
        $normalizado = self::normalizarTitulo($titulo);
        if ($normalizado === null) {
            return 'raw:';
        }

        $datos = self::extraer($titulo);
        if ($datos['porcentaje'] === null) {
            return 'raw:'.mb_strtolower($normalizado);
        }

        // Canónico con punto decimal para que `7,5` y `7.5` colapsen.
        $porcentaje = str_replace(',', '.', $datos['porcentaje']);

        return $porcentaje.'|'.($datos['minimo'] ?? '');
    }

    /** Trim y espacios colapsados. Null si no queda nada. */
    private static function normalizarTitulo(?string $titulo): ?string
    {
        if ($titulo === null) {
            return null;
        }

        $limpio = trim(preg_replace('/\s+/u', ' ', $titulo) ?? '');

        return $limpio !== '' ? $limpio : null;
    }

    /**
     * El porcentaje de la franquicia, en formato local (coma decimal).
     *
     * Con varios porcentajes en el título gana el primero que aparece **después** del marcador
     * (`Franquicia 4%`); si no hay ninguno después, el último de **antes** (`8% ... Franquicia`);
     * y sin marcador, el primero. Así un futuro título con porcentaje de descuento no se confunde
     * con la franquicia.
     */
    private static function buscarPorcentaje(string $titulo): ?string
    {
        if (preg_match_all(self::PORCENTAJE, $titulo, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $encontrados = $matches[1];

        $marcador = preg_match(self::MARCADOR, $titulo, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : null;

        if ($marcador === null) {
            return self::formatearPorcentaje($encontrados[0][0]);
        }

        foreach ($encontrados as [$valor, $offset]) {
            if ($offset > $marcador) {
                return self::formatearPorcentaje($valor);
            }
        }

        $anteriores = array_filter($encontrados, fn (array $p): bool => $p[1] < $marcador);

        return $anteriores === []
            ? self::formatearPorcentaje($encontrados[0][0])
            : self::formatearPorcentaje(end($anteriores)[0]);
    }

    /**
     * Solo los dígitos del monto mínimo. Null si el título no declara uno.
     *
     * Sin signo `$` se exigen al menos 4 dígitos: así "Franquicia Mínima 5%" no confunde el
     * porcentaje con un mínimo de $5, y "Min 400.000" sigue funcionando.
     */
    private static function buscarMinimo(string $titulo): ?string
    {
        if (preg_match(self::MINIMO, $titulo, $m) !== 1) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $m[2]) ?? '';
        if ($digitos === '') {
            return null;
        }

        $esMonto = $m[1] === '$' || strlen($digitos) >= 4;

        return $esMonto ? $digitos : null;
    }

    /** Coma como separador decimal y sin decimales redundantes: `4.0` → `4`, `7.5` → `7,5`. */
    private static function formatearPorcentaje(string $valor): string
    {
        $numerico = (float) str_replace(',', '.', $valor);

        return str_replace('.', ',', (string) round($numerico, 2));
    }
}
