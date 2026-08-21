<?php

namespace App\Console\Commands;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\AgentPrompt;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Prism\Concerns\AddsToolsToPrismRequests;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Prism\Prism\Providers\DeepSeek\Maps\MessageMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use stdClass;

/**
 * Repite N veces el turno de presentación del closer sobre un contexto histórico, para medir
 * cuántas veces toma el camino correcto (llamar `PresentQuoteOptionsTool`) y cuánto tarda.
 *
 * Por qué existe: dos conversaciones de producción con prompt, payload, código y guión IDÉNTICOS
 * (#23 y #24) tardaron 108,2s y 63,5s y generaron 7.699 y 4.578 tokens. Con esa dispersión, ninguna
 * hipótesis sobre latencia o sobre el camino del agente se puede evaluar con una corrida por
 * condición — y cada corrida real cuesta una conversación de WhatsApp de tres minutos.
 *
 * Por qué manda UN SOLO paso y no usa el SDK: la pregunta es qué DECIDE el modelo, no qué pasa
 * después. Leyendo `choices.0.message.tool_calls` sin ejecutar nada, la sonda no escribe en `quotes`,
 * no toca metadata, no despacha jobs y no manda WhatsApp — es segura por construcción y no por
 * convención. Con el SDK no se puede: `DeepSeek\Handlers\Text::handleToolCalls()` llama a
 * `callTools()` ANTES de chequear `shouldContinue()`, así que ni con `maxSteps = 1` se evitan los
 * efectos.
 *
 * Y no pierde casi nada: en la #23 el primer paso fue 103s de los 108s del turno.
 *
 * La fidelidad con producción no se traduce a mano: el payload se arma con los mismos mappers que
 * usa Prism ({@see ToolMap}, {@see MessageMap}) sobre los mismos value objects.
 *
 * Por qué no sirve el Studio: `PromptReevaluationService::resolveToolsForAgent('checkout_closer')`
 * no ofrece `PresentQuoteOptionsTool`, y su `buildContext()` lee la tabla `messages` (solo texto
 * visible), así que el resultado de `get_quote` —el centro del asunto— no estaría en el contexto.
 */
class ProbePresentationTurn extends Command
{
    protected $signature = 'ai:probe-presentation
                            {--runs=10 : Cuántas veces repetir el turno}
                            {--conversation=23 : ID de la conversación cuyo contexto se reevalúa}
                            {--prompt-id= : Pinear una versión de checkout_closer en vez de la activa}
                            {--model= : Correr con otro modelo (por defecto, el tier smartest)}
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

    public function handle(): int
    {
        $apiKey = (string) config('ai.providers.deepseek.key');

        if ($apiKey === '') {
            $this->error('Falta DEEPSEEK_API_KEY.');

            return self::FAILURE;
        }

        $conversationId = (int) $this->option('conversation');
        $conversation = Conversation::find($conversationId);

        if ($conversation === null) {
            $this->error("No existe la conversación {$conversationId}.");

            return self::FAILURE;
        }

        $rows = $this->contextRows($conversationId);

        if ($rows === null) {
            return self::FAILURE;
        }

        $system = $this->systemPrompt();

        if (trim($system) === '') {
            $this->error('El prompt compuesto quedó vacío. ¿Hay una versión activa de checkout_closer?');

            return self::FAILURE;
        }

        $tools = ToolMap::Map($this->prismTools($conversation));
        $messages = (new MessageMap($this->prismMessages($rows), [new SystemMessage($system)]))();

        $model = (string) ($this->option('model')
            ?: config('ai.providers.deepseek.models.text.smartest')
            ?: 'deepseek-v4-pro');

        // El catálogo contra el que se juzga la elección: sin esto la sonda solo puede decir si
        // llamó la tool, no si eligió bien.
        $alternativas = $this->alternativasDelContexto($conversationId, $rows);

        $this->resumenDeEntrada($conversationId, $system, $rows, $tools, $model, count($alternativas));

        $runs = max(1, (int) $this->option('runs'));
        $resultados = [];

        $this->line(sprintf('  %-4s %10s %11s %11s  %s', '#', 'ms', 'prompt_tok', 'compl_tok', 'elección'));

        for ($i = 1; $i <= $runs; $i++) {
            $fila = $this->correr($apiKey, $model, $messages, $tools, $i, $alternativas);

            if ($fila === null) {
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
     * Una pasada contra la API. Nunca ejecuta una tool: solo mira qué pidió el modelo y con qué
     * argumentos.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @param  array<int, array{aseguradora: string, titulo: string, grade: string, precio: float, ofrecible: bool}>  $alternativas
     * @return array<string, mixed>|null
     */
    private function correr(string $apiKey, string $model, array $messages, array $tools, int $run, array $alternativas): ?array
    {
        // La misma URL que usa el provider de Prism, para medir contra el endpoint real.
        $url = rtrim((string) config('prism.providers.deepseek.url', 'https://api.deepseek.com/v1'), '/');

        $arranque = hrtime(true);

        $response = Http::withToken($apiKey)
            ->timeout(400)
            ->post("{$url}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'tools' => $tools,
                // Los dos tal como los manda producción: `auto` está hardcodeado en el SDK
                // (AddsToolsToPrismRequests) y CheckoutAgent no declara #[Temperature], así que
                // mandar una acá mediría otra cosa.
                'tool_choice' => 'auto',
            ]);

        $ms = (int) round((hrtime(true) - $arranque) / 1e6);

        if ($response->failed()) {
            $this->error("  corrida {$run}: HTTP {$response->status()} — ".$response->body());

            return null;
        }

        /** @var list<array<string, mixed>> $llamadas */
        $llamadas = (array) $response->json('choices.0.message.tool_calls', []);

        /** @var list<string> $pedidas */
        $pedidas = collect($llamadas)
            ->map(fn (mixed $tc): string => (string) data_get($tc, 'function.name', '?'))
            ->all();

        // Los argumentos vienen como string JSON, no como objeto.
        $cruda = collect($llamadas)
            ->first(fn (mixed $tc): bool => data_get($tc, 'function.name') === self::TOOL_ESPERADA);

        /** @var array<string, mixed> $argumentos */
        $argumentos = $cruda === null
            ? []
            : (array) json_decode((string) data_get($cruda, 'function.arguments', '{}'), true);

        $eleccion = $this->evaluar($argumentos, $alternativas);

        $fila = [
            'run' => $run,
            'ms' => $ms,
            'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            'finish_reason' => (string) $response->json('choices.0.finish_reason', '?'),
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
            number_format($ms, 0, ',', '.'),
            number_format($fila['prompt_tokens'], 0, ',', '.'),
            number_format($fila['completion_tokens'], 0, ',', '.'),
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
     * El catálogo de la cotización a la que se refiere el contexto. El id sale del `GetQuoteTool`
     * que el `QuoteAgent` llamó en ese mismo turno — que es la fuente exacta, no una inferencia
     * por conversación.
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
     * Las filas del store hasta la última `user` inclusive: ese es el turno a regenerar, así que la
     * respuesta del closer que vino después NO viaja.
     *
     * @return list<stdClass>|null
     */
    private function contextRows(int $conversationId): ?array
    {
        // El store se resuelve por `agent_conversation_messages.user_id` y NO por
        // `agent_conversations.user_id`: esa columna viene inconsistente en producción — está en
        // NULL para las conversaciones 21, 22 y 23, y poblada para la 20 y la 24. La de los
        // mensajes está completa, y además es la tabla de la que se leen las filas.
        $storeIds = DB::table('agent_conversation_messages')
            ->where('user_id', $conversationId)
            ->distinct()
            ->pluck('conversation_id');

        if ($storeIds->count() !== 1) {
            $this->error("Se esperaba 1 conversación de agente para la #{$conversationId}, hay {$storeIds->count()}.");

            return null;
        }

        /** @var list<stdClass> $rows */
        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', (string) $storeIds->first())
            ->orderBy('id')
            ->get()
            ->all();

        $ultimoUser = null;
        foreach ($rows as $idx => $row) {
            if ($row->role === 'user') {
                $ultimoUser = $idx;
            }
        }

        if ($ultimoUser === null) {
            $this->error("La conversación #{$conversationId} no tiene ningún mensaje de usuario en el store.");

            return null;
        }

        return array_slice($rows, 0, $ultimoUser + 1);
    }

    /**
     * Traduce las filas del store a value objects de Prism, con la misma lógica que
     * {@see DatabaseConversationStore::getLatestConversationMessages()}.
     *
     * Reconstruir `tool_calls` y `tool_results` es lo que hace fiel a la sonda: ahí adentro viaja el
     * payload de `get_quote`, que es la variable que estamos estudiando.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, AssistantMessage|ToolResultMessage|UserMessage>
     */
    private function prismMessages(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            if ($row->role === 'user') {
                $messages[] = new UserMessage((string) $row->content);

                continue;
            }

            /** @var list<array<string, mixed>> $toolCalls */
            $toolCalls = (array) json_decode((string) $row->tool_calls, true);
            /** @var list<array<string, mixed>> $toolResults */
            $toolResults = (array) json_decode((string) $row->tool_results, true);

            if ($toolCalls === []) {
                $messages[] = new AssistantMessage((string) $row->content);

                continue;
            }

            $messages[] = new AssistantMessage(
                (string) ($row->content ?: ''),
                array_map(fn (array $tc): ToolCall => new ToolCall(
                    id: (string) $tc['id'],
                    name: (string) $tc['name'],
                    arguments: $tc['arguments'],
                    resultId: $tc['result_id'] ?? null,
                ), $toolCalls),
            );

            if ($toolResults !== []) {
                $messages[] = new ToolResultMessage(
                    array_map(fn (array $tr): ToolResult => new ToolResult(
                        toolCallId: (string) $tr['id'],
                        toolName: (string) $tr['name'],
                        args: (array) ($tr['arguments'] ?? []),
                        result: $tr['result'],
                        toolCallResultId: $tr['result_id'] ?? null,
                    ), $toolResults),
                );
            }
        }

        return $messages;
    }

    /**
     * Las 5 tools del closer, envueltas igual que {@see AddsToolsToPrismRequests::createPrismTool()}.
     *
     * Ninguna sobreescribe `name()`, así que el nombre que viaja es `class_basename` — que es el que
     * se ve en `agent_execution_logs.tool_calls`.
     *
     * Instanciarlas es inofensivo: acá solo se leen `description()` y `schema()`; los `handle()`
     * nunca se invocan porque la sonda no ejecuta el tool loop.
     *
     * @return array<int, PrismTool>
     */
    private function prismTools(Conversation $conversation): array
    {
        /** @var WhatsAppAdapter $adapter */
        $adapter = app(WhatsAppAdapter::class);

        /** @var array<int, Tool> $tools */
        $tools = [
            new CheckoutTool($adapter, $conversation),
            new CheckCoverageRuleTool,
            new RevertStageTool($adapter, $conversation),
            new PresentQuoteOptionsTool($conversation),
            new SiniestroGuidanceTool($conversation),
        ];

        return array_map(function (Tool $tool): PrismTool {
            $schema = $tool->schema(new JsonSchemaTypeFactory);

            $prismTool = (new PrismTool)
                ->as(class_basename($tool))
                ->for((string) $tool->description());

            if ($schema !== []) {
                $prismTool = $prismTool->withParameter(new ObjectSchema($schema));
            }

            return $prismTool;
        }, $tools);
    }

    /** El prompt tal como lo compone el runtime, o una versión pineada con --prompt-id. */
    private function systemPrompt(): string
    {
        $pinned = $this->option('prompt-id');

        if ($pinned === null) {
            return AgentPrompt::compose('checkout_closer', self::SHARED_BLOCKS);
        }

        $version = AgentPrompt::find((int) $pinned);

        if ($version === null) {
            return '';
        }

        // Mismo orden que compose(): los compartidos primero, el del agente al final.
        return collect(self::SHARED_BLOCKS)
            ->map(fn (string $key): ?string => AgentPrompt::activeFor($key)?->content)
            ->push($version->content)
            ->filter()
            ->implode("\n\n");
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  array<array-key, mixed>  $tools
     */
    private function resumenDeEntrada(int $conversationId, string $system, array $rows, array $tools, string $model, int $alternativas): void
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

        $ms = collect($resultados)->pluck('ms');
        $tok = collect($resultados)->pluck('completion_tokens');

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

        $this->line("  corridas ................. {$total}");
        $this->line("  llamó la tool ............ {$conLaTool}/{$total}  (".round($conLaTool * 100 / $total).' %)');
        $this->line("  presentaciones válidas ... {$validas}/{$total}  (".round($validas * 100 / $total).' %)');
        $this->line('  pares elegidos ........... '.($pares->isEmpty() ? '(ninguno)' : $pares->implode(' · ')));
        $this->line('  recomendada .............. '.($recomendadas->isEmpty() ? '(ninguna)' : $recomendadas->implode(' · ')));
        $this->line('  fallos ................... '.($fallos->isEmpty() ? '(ninguno)' : $fallos->implode(' · ')));
        $this->line('  otras tools .............. '.($otras->isEmpty() ? '(ninguna)' : $otras->implode(', ')));
        $this->line('  latencia ................. '.$this->tramo($ms->all(), 1000, ' s'));
        $this->line('  completion_tokens ........ '.$this->tramo($tok->all(), 1, ''));
        $this->newLine();

        $this->line(match (true) {
            $validas === $total => '  → Camino y elección correctos en todas las corridas.',
            $conLaTool === $total => '  → Llama la tool siempre, pero hay elecciones inválidas: mirar los fallos.',
            default => '  → El camino NO es determinista: hay corridas que se saltean la tool.',
        });

        $this->line('  → Las razones son prosa: leerlas del volcado --json para juzgar redacción.');
    }

    /**
     * @param  list<int>  $valores
     */
    private function tramo(array $valores, int $divisor, string $sufijo): string
    {
        sort($valores);
        $n = count($valores);
        $fmt = fn (float $v): string => $divisor > 1
            ? number_format($v / $divisor, 1, ',', '.').$sufijo
            : number_format($v, 0, ',', '.').$sufijo;

        return 'min '.$fmt((float) $valores[0])
            .' · p50 '.$fmt((float) $valores[intdiv($n - 1, 2)])
            .' · max '.$fmt((float) $valores[$n - 1]);
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
