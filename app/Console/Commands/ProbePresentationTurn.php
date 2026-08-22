<?php

namespace App\Console\Commands;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Agents\CheckoutAgent;
use App\AI\Probes\DeepSeekProbe;
use App\AI\Probes\ProbeStats;
use App\AI\Probes\TurnRequest;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use stdClass;
use Throwable;

/**
 * Repite N veces el turno de presentación del closer sobre un contexto histórico, para medir si
 * toma el camino correcto (llamar `PresentQuoteOptionsTool`), si elige bien, y cuánto tarda.
 *
 * Por qué existe: dos conversaciones de producción con prompt, payload, código y guión IDÉNTICOS
 * (#23 y #24) tardaron 108,2s y 63,5s y generaron 7.699 y 4.578 tokens. Con esa dispersión, ninguna
 * hipótesis se puede evaluar con una corrida por condición — y cada corrida real cuesta una
 * conversación de WhatsApp de tres minutos.
 *
 * Manda UN SOLO paso y lee lo que el modelo pidió, sin ejecutar nada: ver {@see DeepSeekProbe}.
 * No pierde casi nada — en la #23 el primer paso fue 103s de los 108s del turno.
 */
class ProbePresentationTurn extends Command
{
    protected $signature = 'ai:probe-presentation
                            {--runs=10 : Cuántas veces repetir el turno}
                            {--conversation=23 : ID de la conversación cuyo contexto se reevalúa}
                            {--prompt-id= : Pinear una versión de checkout_closer en vez de la activa}
                            {--model= : Correr con otro modelo (por defecto, el tier del agente)}
                            {--json= : Volcar las corridas crudas a este archivo}';

    protected $description = 'Repite el turno del closer sobre un contexto histórico y mide camino, calidad y latencia';

    /** Los mismos bloques que declara CheckoutAgent::$sharedBlocks. */
    private const SHARED_BLOCKS = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    /** El camino correcto: la tool que arma botones, recomendación y link al comparador. */
    private const TOOL_ESPERADA = 'PresentQuoteOptionsTool';

    /**
     * Los dos grados que el cliente de este contexto pidió comparar: "terceros completos y todo
     * riesgo". `third_party_complete_plus` cuenta como C — al cliente se le muestran con el mismo
     * nombre, la distinción es interna.
     */
    private const FAMILIA_C = ['third_party_complete', 'third_party_complete_plus'];

    private const FAMILIA_D = ['all_risk'];

    public function handle(DeepSeekProbe $probe): int
    {
        if (DeepSeekProbe::apiKey() === '') {
            $this->error('Falta DEEPSEEK_API_KEY.');

            return self::FAILURE;
        }

        $conversationId = (int) $this->option('conversation');
        $conversation = Conversation::find($conversationId);

        if ($conversation === null) {
            $this->error("No existe la conversación {$conversationId}.");

            return self::FAILURE;
        }

        try {
            $rows = TurnRequest::rowsUpToLastUser($conversationId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $pinned = $this->option('prompt-id');
        $system = TurnRequest::system('checkout_closer', self::SHARED_BLOCKS, $pinned === null ? null : (int) $pinned);

        if (trim($system) === '') {
            $this->error('El prompt compuesto quedó vacío. ¿Hay una versión activa de checkout_closer?');

            return self::FAILURE;
        }

        $tools = TurnRequest::toolPayload($this->tools($conversation));
        $messages = TurnRequest::payload(TurnRequest::prismMessages($rows), $system);
        $model = (string) ($this->option('model') ?: DeepSeekProbe::modelFor(CheckoutAgent::class));

        // El catálogo contra el que se juzga la elección: sin esto la sonda solo puede decir si
        // llamó la tool, no si eligió bien.
        $alternativas = $this->alternativasDelContexto($conversationId, $rows);

        $this->cabecera($conversationId, $system, $rows, $tools, $model, count($alternativas));

        $runs = max(1, (int) $this->option('runs'));
        $resultados = [];

        $this->line(sprintf('  %-4s %10s %11s %11s  %s', '#', 'ms', 'prompt_tok', 'compl_tok', 'elección'));

        for ($i = 1; $i <= $runs; $i++) {
            try {
                $fila = $this->correr($probe, $model, $messages, $tools, $i, $alternativas);
            } catch (Throwable $e) {
                $this->error("  corrida {$i}: ".$e->getMessage());

                return self::FAILURE;
            }

            $resultados[] = $fila;
        }

        $this->volcarJson($resultados);
        $this->newLine();
        $this->resumen($resultados);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @param  array<int, array{aseguradora: string, titulo: string, grade: string, precio: float, ofrecible: bool}>  $alternativas
     * @return array<string, mixed>
     */
    private function correr(DeepSeekProbe $probe, string $model, array $messages, array $tools, int $run, array $alternativas): array
    {
        $r = $probe->send($model, $messages, $tools);

        /** @var list<string> $pedidas */
        $pedidas = collect($r['tool_calls'])
            ->map(fn (mixed $tc): string => (string) data_get($tc, 'function.name', '?'))
            ->all();

        $cruda = collect($r['tool_calls'])
            ->first(fn (mixed $tc): bool => data_get($tc, 'function.name') === self::TOOL_ESPERADA);

        $argumentos = $cruda === null
            ? []
            : TurnRequest::unwrapArguments((string) data_get($cruda, 'function.arguments', '{}'));

        $eleccion = $this->evaluar($argumentos, $alternativas);

        $fila = [
            'run' => $run,
            'ms' => $r['ms'],
            'prompt_tokens' => $r['prompt_tokens'],
            'completion_tokens' => $r['completion_tokens'],
            'finish_reason' => $r['finish_reason'],
            'tools' => $pedidas,
            'arguments' => $argumentos,
            'eleccion' => $eleccion,
        ];

        $marca = match (true) {
            ! in_array(self::TOOL_ESPERADA, $pedidas, true) => '<comment>✗</comment>',
            $eleccion['valida'] => '<info>✔</info>',
            default => '<comment>!</comment>',
        };

        $this->line(sprintf(
            '  %-4s %10s %11s %11s  %s %s',
            $run,
            number_format($r['ms'], 0, ',', '.'),
            number_format($r['prompt_tokens'], 0, ',', '.'),
            number_format($r['completion_tokens'], 0, ',', '.'),
            $marca,
            $eleccion['etiqueta'],
        ));

        return $fila;
    }

    /**
     * Juzga la elección con chequeos objetivos contra la base — nada de criterio subjetivo. Las
     * razones son prosa y no se pueden contar: van completas al volcado `--json` para leerlas.
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, array{aseguradora: string, titulo: string, grade: string, precio: float, ofrecible: bool}>  $alternativas
     * @return array{valida: bool, par: string, recomendada: ?int, fallos: list<string>, etiqueta: string}
     */
    private function evaluar(array $args, array $alternativas): array
    {
        if ($args === []) {
            return ['valida' => false, 'par' => '', 'recomendada' => null, 'fallos' => ['sin_tool'], 'etiqueta' => '(no llamó la tool)'];
        }

        $ids = array_values(array_map(intval(...), (array) ($args['alternative_ids'] ?? [])));
        $recomendada = isset($args['recommended_alternative_id']) ? (int) $args['recommended_alternative_id'] : null;
        $fallos = [];

        $conocidas = array_values(array_filter($ids, fn (int $id): bool => isset($alternativas[$id])));

        if (count($ids) !== 2) {
            $fallos[] = 'cantidad';
        }

        if (count($conocidas) !== count($ids)) {
            $fallos[] = 'id_ajeno';
        }

        if ($recomendada === null || ! in_array($recomendada, $ids, true)) {
            $fallos[] = 'recomendada_fuera';
        }

        foreach (['recommended_reason', 'alternative_reason'] as $campo) {
            if (trim((string) ($args[$campo] ?? '')) === '') {
                $fallos[] = 'razon_vacia';

                break;
            }
        }

        $grades = array_map(fn (int $id): string => $alternativas[$id]['grade'], $conocidas);

        if (array_filter($grades, fn (string $g): bool => in_array($g, self::FAMILIA_C, true)) === []
            || array_filter($grades, fn (string $g): bool => in_array($g, self::FAMILIA_D, true)) === []) {
            $fallos[] = 'grades_no_pedidos';
        }

        if (array_filter($conocidas, fn (int $id): bool => ! $alternativas[$id]['ofrecible']) !== []) {
            $fallos[] = 'no_ofrecible';
        }

        // El par se ordena para que dos corridas que eligieron lo mismo en distinto orden cuenten
        // como la misma elección.
        $ordenados = $ids;
        sort($ordenados);

        return [
            'valida' => $fallos === [],
            'par' => implode('+', $ordenados),
            'recomendada' => $recomendada,
            'fallos' => $fallos,
            'etiqueta' => $this->etiqueta($ids, $recomendada, $alternativas, $fallos),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array{aseguradora: string, titulo: string, grade: string, precio: float, ofrecible: bool}>  $alternativas
     * @param  list<string>  $fallos
     */
    private function etiqueta(array $ids, ?int $recomendada, array $alternativas, array $fallos): string
    {
        $partes = array_map(function (int $id) use ($recomendada, $alternativas): string {
            $alt = $alternativas[$id] ?? null;

            if ($alt === null) {
                return ($id === $recomendada ? '★' : '').$id.' (desconocida)';
            }

            return ($id === $recomendada ? '★' : '')
                .$alt['aseguradora'].' '.$alt['titulo']
                .' $'.number_format($alt['precio'] / 1000, 1, ',', '.').'K'
                .' ('.$this->sigla($alt['grade']).')';
        }, $ids);

        return implode(' / ', $partes).($fallos === [] ? '' : '  <comment>['.implode(' ', $fallos).']</comment>');
    }

    private function sigla(string $grade): string
    {
        return match ($grade) {
            'liability' => 'A',
            'basic' => 'B',
            'third_party_complete' => 'C',
            'third_party_complete_plus' => 'C+A',
            'all_risk' => 'D',
            default => $grade,
        };
    }

    /**
     * Las 5 tools que `InsuranceOrchestrator::resolveAgent()` le da al closer en el paso 5.
     *
     * @return array<int, Tool>
     */
    private function tools(Conversation $conversation): array
    {
        /** @var WhatsAppAdapter $adapter */
        $adapter = app(WhatsAppAdapter::class);

        return [
            new CheckoutTool($adapter, $conversation),
            new CheckCoverageRuleTool,
            new RevertStageTool($adapter, $conversation),
            new PresentQuoteOptionsTool($conversation),
            new SiniestroGuidanceTool($conversation),
        ];
    }

    /**
     * El catálogo de la cotización a la que se refiere el contexto. El id sale del `GetQuoteTool`
     * que el `QuoteAgent` llamó en ese mismo turno — la fuente exacta, no una inferencia.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, array{aseguradora: string, titulo: string, grade: string, precio: float, ofrecible: bool}>
     */
    private function alternativasDelContexto(int $conversationId, array $rows): array
    {
        $quoteId = null;

        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row->tool_calls, true) as $tc) {
                if (($tc['name'] ?? null) === 'GetQuoteTool') {
                    $quoteId = (int) ($tc['arguments']['quoteId'] ?? 0);
                }
            }
        }

        if ($quoteId === null || $quoteId === 0) {
            $quoteId = (int) DB::table('quotes')->where('conversation_id', $conversationId)->max('id');
        }

        /** @var list<string> $cobrables */
        $cobrables = (array) config('quotes.medios_de_pago_ofrecibles', []);

        return DB::table('quote_alternatives')
            ->where('quote_id', $quoteId)
            ->get()
            ->mapWithKeys(fn (stdClass $a): array => [(int) $a->id => [
                'aseguradora' => (string) $a->aseguradora,
                'titulo' => (string) $a->titulo,
                'grade' => (string) $a->normalized_grade,
                'precio' => (float) $a->precio,
                'ofrecible' => $a->payment_method_id === null
                    || in_array((string) $a->payment_method_id, $cobrables, true),
            ]])
            ->all();
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  array<array-key, mixed>  $tools
     */
    private function cabecera(int $conversationId, string $system, array $rows, array $tools, string $model, int $alternativas): void
    {
        /** @var list<string> $nombres */
        $nombres = collect($tools)->map(fn (mixed $t): string => (string) data_get($t, 'function.name', '?'))->all();

        $this->newLine();
        $this->line("  conversación  <options=bold>#{$conversationId}</>");
        $this->line("  modelo        <options=bold>{$model}</>");
        $this->line('  prompt        '.number_format(mb_strlen($system), 0, ',', '.').' caracteres'
            .($this->option('prompt-id') !== null ? ' (id '.$this->option('prompt-id').')' : ' (versión activa)'));
        $this->line('  contexto      '.count($rows).' filas del store');
        $this->line('  catálogo      '.$alternativas.' alternativas para juzgar la elección');
        $this->line('  tools         '.implode(', ', $nombres));
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     */
    private function resumen(array $resultados): void
    {
        $total = count($resultados);
        $conLaTool = collect($resultados)
            ->filter(fn (array $r): bool => in_array(self::TOOL_ESPERADA, $r['tools'], true))
            ->count();

        $otras = collect($resultados)
            ->flatMap(fn (array $r): array => $r['tools'])
            ->reject(fn (string $n): bool => $n === self::TOOL_ESPERADA)
            ->countBy()
            ->map(fn (int $n, string $name): string => "{$name} ×{$n}")
            ->values();

        $validas = collect($resultados)->filter(fn (array $r): bool => $r['eleccion']['valida'])->count();

        // La dispersión de pares es el dato central de calidad: un modelo que delibera menos puede
        // llamar la tool igual y elegir cualquier cosa.
        $pares = collect($resultados)
            ->pluck('eleccion.par')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->map(fn (int $n, string $par): string => "{$par} ×{$n}")
            ->values();

        $recomendadas = collect($resultados)
            ->pluck('eleccion.recomendada')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->map(fn (int $n, int|string $id): string => "{$id} ×{$n}")
            ->values();

        $fallos = collect($resultados)
            ->flatMap(fn (array $r): array => $r['eleccion']['fallos'])
            ->countBy()
            ->map(fn (int $n, string $f): string => "{$f} ×{$n}")
            ->values();

        /** @var list<int> $ms */
        $ms = collect($resultados)->pluck('ms')->all();
        /** @var list<int> $tok */
        $tok = collect($resultados)->pluck('completion_tokens')->all();

        $this->line("  corridas ................. {$total}");
        $this->line("  llamó la tool ............ {$conLaTool}/{$total}  (".round($conLaTool * 100 / $total).' %)');
        $this->line("  presentaciones válidas ... {$validas}/{$total}  (".round($validas * 100 / $total).' %)');
        $this->line('  pares elegidos ........... '.($pares->isEmpty() ? '(ninguno)' : $pares->implode(' · ')));
        $this->line('  recomendada .............. '.($recomendadas->isEmpty() ? '(ninguna)' : $recomendadas->implode(' · ')));
        $this->line('  fallos ................... '.($fallos->isEmpty() ? '(ninguno)' : $fallos->implode(' · ')));
        $this->line('  otras tools .............. '.($otras->isEmpty() ? '(ninguna)' : $otras->implode(', ')));
        $this->line('  latencia ................. '.ProbeStats::tramo($ms, 1000, ' s'));
        $this->line('  completion_tokens ........ '.ProbeStats::tramo($tok));
        $this->newLine();

        $this->line(match (true) {
            $validas === $total => '  → Camino y elección correctos en todas las corridas.',
            $conLaTool === $total => '  → Llama la tool siempre, pero hay elecciones inválidas: mirar los fallos.',
            default => '  → El camino NO es determinista: hay corridas que se saltean la tool.',
        });

        $this->line('  → Las razones son prosa: leerlas del volcado --json para juzgar redacción.');
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     */
    private function volcarJson(array $resultados): void
    {
        $ruta = $this->option('json');

        if ($ruta === null) {
            return;
        }

        file_put_contents((string) $ruta, (string) json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->line("  corridas volcadas a {$ruta}");
    }
}
