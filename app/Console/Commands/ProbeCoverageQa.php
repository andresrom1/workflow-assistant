<?php

namespace App\Console\Commands;

use App\AI\Probes\ProbeStats;
use App\AI\Tools\CheckCoverageRuleTool;
use App\Models\QuoteAlternative;
use Illuminate\Console\Command;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Banco de preguntas de cobertura con respuesta conocida, corrido N veces contra el modelo.
 *
 * Por qué existe: hasta hoy no había ninguna medición del camino de coberturas — ni un test ni
 * una sonda. La única evidencia era una corrida manual de 7 consultas que acertó 2. Sin esto,
 * cuán estricta quedó la verificación de la Fase 3 se elige a ojo.
 *
 * Lo que se mide NO es "cuántas acertó". Son cinco resultados, porque los errores no cuestan lo
 * mismo:
 *
 * |                     | veredicto correcto | veredicto incorrecto | se abstuvo      |
 * |---------------------|--------------------|----------------------|-----------------|
 * | **debía contestar** | acierto            | GRAVE                | venta perdida   |
 * | **debía abstenerse**| —                  | GRAVE                | acierto         |
 *
 * `GRAVE` es la celda que importa: el agente afirmó algo que no puede sostener, y esa promesa la
 * hace el PAS sin que la compañía la haya validado. `venta perdida` es el costo del lado opuesto,
 * y existe para que apretar la verificación no salga gratis en la medición.
 *
 * A diferencia de las otras sondas, ésta **sí ejecuta la tool real**, y puede: todo el camino de
 * `CheckCoverageRuleTool` es de lectura — compone el prompt, busca la alternativa, lee los
 * documentos de la compañía y llama al experto. No escribe en la base, no despacha jobs y no manda
 * WhatsApp. Por eso mide el comportamiento de verdad y no una reconstrucción.
 *
 * Los casos se resuelven por `aseguradora` + `titulo` contra la base donde corra, así que el mismo
 * banco sirve en local y en producción. Los que no encuentran su plan se reportan como omitidos —
 * un banco que silenciosamente mide la mitad es peor que uno que falla.
 *
 * Siempre N corridas: dos corridas idénticas del mismo modelo con el mismo contexto difirieron
 * 1,7× en latencia y tokens (ver ROADMAP, bitácora 2026-08-21). Con n=1 no se concluye nada.
 */
class ProbeCoverageQa extends Command
{
    protected $signature = 'ai:probe-coverage-qa
                            {--runs=3 : Cuántas veces repetir cada caso}
                            {--caso= : Correr sólo este caso, por su clave}
                            {--json= : Volcar las corridas crudas a este archivo}';

    protected $description = 'Mide el banco de preguntas de cobertura: acierto, afirmación equivocada y abstención';

    /**
     * El banco. Cada caso dice qué se espera y POR QUÉ, que es lo que permite revisarlo.
     *
     * `espera`:
     *   - `cubierto` / `no_cubierto` → tiene respaldo, y ése es el veredicto correcto
     *   - `abstenerse` → no hay con qué contestar; afirmar cualquier cosa es el error grave
     *
     * @var array<string, array{aseguradora: string, titulo: string, pregunta: string, espera: string, por_que: string}>
     */
    private const CASOS = [
        // ── Debe contestar ──────────────────────────────────────────────────────
        'granizo-si' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'Auto Plus +',
            'pregunta' => 'me cubre el granizo?',
            'espera' => 'cubierto',
            'por_que' => 'Granizo está en las 15 coberturas del plan.',
        ],
        'granizo-no' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'C - Auto Plus',
            'pregunta' => 'me cubre el granizo?',
            'espera' => 'no_cubierto',
            'por_que' => 'Las 12 coberturas del plan no incluyen Granizo, y la enumeración vino completa. Mismo grado "C" que Auto Plus +, que sí lo trae.',
        ],
        'robo-en-rc' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'A - Responsabilidad Civil',
            'pregunta' => 'si me roban el auto me lo cubren?',
            'espera' => 'no_cubierto',
            'por_que' => 'El plan enumera exactamente 2 coberturas y ninguna es robo.',
        ],
        'espejos' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'C - Auto Plus',
            'pregunta' => 'si me roban los espejos retrovisores me los cubren?',
            'espera' => 'no_cubierto',
            'por_que' => 'Robo Parcial los excluye textualmente: "a excepción de ... Espejos retrovisores".',
        ],
        'franquicia' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'Todo Riesgo Franquicia 7,5% suma asegurada',
            'pregunta' => 'cuanto es la franquicia en pesos?',
            'espera' => 'cubierto',
            'por_que' => 'Derivable del título por la suma asegurada; el bloque la entrega calculada.',
        ],

        'cerraduras-tope' => [
            'aseguradora' => 'Rio Uruguay', 'titulo' => 'Sigma',
            'pregunta' => 'hasta cuanto me cubren las cerraduras?',
            'espera' => 'cubierto',
            'por_que' => 'El manual de Río Uruguay es el mejor extraído (47,6 pipes/1000 chars) y trae "Cerraduras (1) $300.000". El plan Sigma SÍ figura, nombrado igual. Este caso existe para medir el costo del chequeo de presencia del plan: si degradara acá, el chequeo está de más.',
        ],

        // ── Debe abstenerse ─────────────────────────────────────────────────────
        'grua-reintegro' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'C - Auto Plus',
            'pregunta' => 'si la grua no viene, cuantos minutos tengo que esperar para pedir una particular y despues pedir el reintegro?',
            'espera' => 'abstenerse',
            'por_que' => 'Procedimiento de siniestro. No está en la cotización ni en un manual de suscripción. TRAMPA: la grúa SÍ figura como cobertura, así que el paso 1 confirma que existe — y de ahí es fácil completar lo que no se sabe.',
        ],
        'gnc' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'C - Auto Plus',
            'pregunta' => 'me cubren el robo del equipo de GNC?',
            'espera' => 'abstenerse',
            'por_que' => 'Predicado vago: cabe en "elementos fijos que hacen al funcionamiento" y no está en la lista de exclusiones. El texto no lo resuelve.',
        ],
        'sin-enumeracion' => [
            'aseguradora' => 'Sancor', 'titulo' => 'Auto Max 15',
            'pregunta' => 'me cubre el granizo?',
            'espera' => 'abstenerse',
            'por_que' => 'Visred manda este plan con features vacío. No se puede negar por ausencia: falta el dato, no la cobertura.',
        ],
        'km-grua' => [
            'aseguradora' => 'San Cristobal', 'titulo' => 'C - Auto Plus',
            'pregunta' => 'cuantos kilometros de grua me cubren?',
            'espera' => 'abstenerse',
            'por_que' => 'El texto extraído dice "dentro del límite de kilómetros establecido" sin el número: la tabla no sobrevivió a la extracción.',
        ],
        'triunfo-tope-granizo' => [
            'aseguradora' => 'Triunfo', 'titulo' => 'C2 FUll',
            'pregunta' => 'hasta cuanto me cubren por granizo?',
            'espera' => 'cubierto',
            'por_que' => 'El manual tiene una sección "## C2 FULL" que dice textualmente "Cláusula CA-DA 1.1 Adicional de Daño Parcial por Granizo hasta la suma asegurada de $500.000.-". CORRECCIÓN: este caso esperaba abstención, sobre el supuesto de que C2 FUll no figuraba en el manual. El supuesto venía de la sonda RAG vieja, que ante esta pregunta devolvía el cuadro de CAMIONES — un artefacto del retrieval, no del manual. Con el documento completo el modelo ubica la sección correcta, así que este caso pasó a ser la prueba de que la Fase 2 arregló justo eso.',
        ],
    ];

    public function handle(): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $casos = self::CASOS;

        if (is_string($clave = $this->option('caso')) && $clave !== '') {
            if (! isset($casos[$clave])) {
                $this->error("Caso desconocido: {$clave}. Disponibles: ".implode(', ', array_keys(self::CASOS)));

                return self::FAILURE;
            }

            $casos = [$clave => $casos[$clave]];
        }

        $tool = new CheckCoverageRuleTool;
        $celdas = ['acierto' => 0, 'grave' => 0, 'venta_perdida' => 0, 'error' => 0];
        $latencias = [];
        $crudas = [];
        $omitidos = [];

        foreach ($casos as $clave => $caso) {
            $alt = QuoteAlternative::where('aseguradora', $caso['aseguradora'])
                ->where('titulo', $caso['titulo'])
                ->orderByDesc('id')
                ->first();

            if (! $alt instanceof QuoteAlternative) {
                $omitidos[] = "{$clave} ({$caso['aseguradora']} / {$caso['titulo']})";

                continue;
            }

            $this->line('');
            $this->line("<options=bold>{$clave}</> — {$caso['aseguradora']} / {$caso['titulo']}");
            $this->line("  <fg=gray>{$caso['pregunta']}</>");
            $this->line('  <fg=gray>espera: '.$caso['espera'].' — '.$caso['por_que'].'</>');

            for ($i = 1; $i <= $runs; $i++) {
                $inicio = (int) (microtime(true) * 1000);

                try {
                    $salida = json_decode($tool->handle(new Request([
                        'evento' => $caso['pregunta'],
                        'aseguradora' => $caso['aseguradora'],
                        'quote_alternative_id' => (string) $alt->id,
                        'antiguedad_vehiculo' => 'desconocida',
                    ])), true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    $celdas['error']++;
                    $this->line("    <fg=red>error</> {$e->getMessage()}");

                    continue;
                }

                $ms = (int) (microtime(true) * 1000) - $inicio;
                $latencias[] = $ms;

                $veredicto = is_array($salida) && is_string($salida['veredicto'] ?? null) ? $salida['veredicto'] : '(vacio)';
                $celda = $this->clasificar($caso['espera'], $veredicto);
                $celdas[$celda]++;

                $crudas[] = ['caso' => $clave, 'corrida' => $i, 'ms' => $ms, 'celda' => $celda, 'salida' => $salida];

                $this->line(sprintf(
                    '    %s  %-16s %5s ms  %s',
                    $this->icono($celda),
                    $veredicto,
                    number_format($ms),
                    $this->recorte(is_array($salida) ? (string) ($salida['respuesta'] ?? '') : ''),
                ));

                if (is_array($salida) && is_string($salida['degradado_por'] ?? null)) {
                    $this->line("       <fg=yellow>degradado: {$salida['degradado_por']}</>");
                }
            }
        }

        $this->tabla($celdas, $latencias, $omitidos);

        if (is_string($ruta = $this->option('json')) && $ruta !== '') {
            file_put_contents($ruta, json_encode([
                '_meta' => ['entorno' => config('app.env'), 'generado' => now()->toIso8601String(), 'runs' => $runs],
                'celdas' => $celdas,
                'omitidos' => $omitidos,
                'corridas' => $crudas,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->info("Crudas en {$ruta}");
        }

        return $celdas['grave'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * La celda de la matriz. `sin_verificar` cuenta como abstención: el camino de búsqueda no
     * da garantía, así que su respuesta no se computa como fundada.
     */
    private function clasificar(string $espera, string $veredicto): string
    {
        $seAbstuvo = in_array($veredicto, ['no_especificado', 'sin_verificar'], true);

        if ($espera === 'abstenerse') {
            return $seAbstuvo ? 'acierto' : 'grave';
        }

        if ($seAbstuvo) {
            return 'venta_perdida';
        }

        return $veredicto === $espera ? 'acierto' : 'grave';
    }

    private function icono(string $celda): string
    {
        return match ($celda) {
            'acierto' => '<fg=green>✓</>',
            'grave' => '<fg=red>✗</>',
            'venta_perdida' => '<fg=yellow>~</>',
            default => '?',
        };
    }

    private function recorte(string $texto): string
    {
        $limpio = (string) preg_replace('/\s+/u', ' ', $texto);

        return mb_strlen($limpio) > 70 ? mb_substr($limpio, 0, 70).'…' : $limpio;
    }

    /**
     * @param  array<string, int>  $celdas
     * @param  list<int>  $latencias
     * @param  list<string>  $omitidos
     */
    private function tabla(array $celdas, array $latencias, array $omitidos): void
    {
        $total = array_sum($celdas);

        $this->line('');
        $this->line('<options=bold>Resultado</>');
        $this->table(
            ['celda', 'n', '%'],
            collect($celdas)->map(fn (int $n, string $k): array => [
                $k, $n, $total > 0 ? number_format($n / $total * 100, 1).'%' : '—',
            ])->values()->all(),
        );

        $this->line('  latencia por consulta: '.ProbeStats::tramo($latencias, 1000, ' s'));

        if ($omitidos !== []) {
            $this->line('');
            $this->warn('Casos omitidos porque su plan no está en esta base:');
            foreach ($omitidos as $o) {
                $this->line("  - {$o}");
            }
        }

        if ($celdas['grave'] > 0) {
            $this->line('');
            $this->error("{$celdas['grave']} respuesta(s) en la celda GRAVE: afirmó sin poder sostenerlo.");
        }
    }
}
