<?php

namespace App\Support;

use App\Models\QuoteAlternative;
use Illuminate\Support\Str;

/**
 * Mide si el texto de un manual esta en condiciones de sostener una respuesta de cobertura.
 *
 * No juzga el contenido —eso lo hace `ai:probe-coverage-qa`— sino la forma: un manual con las
 * tablas aplanadas o con la mitad del texto perdido produce respuestas equivocadas dichas con
 * seguridad, y eso se detecta sin leer nada.
 *
 * La metrica que mas importa es la ultima: **que planes de la cotizacion figuran en el texto**.
 * `CheckCoverageRuleTool` degrada a `no_especificado` cuando no encuentra el nombre del plan en
 * la documentacion, asi que cada plan ausente es una consulta que el agente no va a poder
 * contestar aunque la respuesta este ahi.
 */
final class CoverageTextMetrics
{
    /**
     * Densidad minima de pipes por 1.000 caracteres en un documento con cuadro de coberturas.
     * Referencias medidas: Rio Uruguay 47,6 · Galicia 26,4 · Sancor 16,6 · Triunfo 0,7 (perdidas).
     */
    public const DENSIDAD_PIPES_MINIMA = 15.0;

    /**
     * @return array{
     *     chars: int,
     *     pipes: int,
     *     densidad_pipes: float,
     *     headers: int,
     *     planes_totales: int,
     *     planes_presentes: list<string>,
     *     planes_ausentes: list<string>,
     * }
     */
    public static function medir(string $texto, string $companySlug): array
    {
        $chars = mb_strlen($texto);
        $pipes = mb_substr_count($texto, '|');
        $planes = self::planesCotizados($companySlug);

        $presentes = [];
        $ausentes = [];

        foreach ($planes as $plan) {
            if (self::figura($plan, $texto)) {
                $presentes[] = $plan;
            } else {
                $ausentes[] = $plan;
            }
        }

        return [
            'chars' => $chars,
            'pipes' => $pipes,
            'densidad_pipes' => $chars > 0 ? round($pipes / $chars * 1000, 1) : 0.0,
            'headers' => preg_match_all('/^#{1,4}\s/m', $texto),
            'planes_totales' => count($planes),
            'planes_presentes' => $presentes,
            'planes_ausentes' => $ausentes,
        ];
    }

    /**
     * Los titulos de plan que la compania efectivamente cotiza, que son los que el agente va a
     * tener que buscar en el manual.
     *
     * @return list<string>
     */
    public static function planesCotizados(string $companySlug): array
    {
        $aseguradoras = QuoteAlternative::query()
            ->distinct()
            ->pluck('aseguradora')
            ->filter(fn (mixed $n): bool => Str::slug((string) $n) === $companySlug)
            ->all();

        if ($aseguradoras === []) {
            return [];
        }

        return QuoteAlternative::query()
            ->whereIn('aseguradora', $aseguradoras)
            ->distinct()
            ->pluck('titulo')
            ->map(fn (mixed $t): string => (string) $t)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * La misma comparacion tolerante que usa la verificacion de `CheckCoverageRuleTool`: sin
     * acentos, sin mayusculas y sin puntuacion, porque el manual escribe `AUTO PLUS MAS (C+)`
     * donde la cotizacion dice `Auto Plus +`.
     */
    private static function figura(string $plan, string $texto): bool
    {
        $normalizado = self::normalizar($plan);

        return $normalizado !== '' && str_contains(self::normalizar($texto), $normalizado);
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $texto));
    }
}
