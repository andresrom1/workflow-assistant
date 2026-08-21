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
                            {--json= : Volcar las corridas crudas a este archivo}';

    protected $description = 'Repite el turno del closer sobre un contexto histórico y mide camino y latencia';

    /** Los mismos bloques que declara CheckoutAgent::$sharedBlocks. */
    private const SHARED_BLOCKS = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    /** El camino correcto: la tool que arma botones, recomendación y link al comparador. */
    private const TOOL_ESPERADA = 'PresentQuoteOptionsTool';

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

        $this->resumenDeEntrada($conversationId, $system, $rows, $tools);

        $runs = max(1, (int) $this->option('runs'));
        $model = (string) (config('ai.providers.deepseek.models.text.smartest') ?? 'deepseek-v4-pro');
        $resultados = [];

        $this->line(sprintf('  %-4s %10s %14s %12s  %s', '#', 'ms', 'prompt_tok', 'compl_tok', 'tools pedidas'));

        for ($i = 1; $i <= $runs; $i++) {
            $fila = $this->correr($apiKey, $model, $messages, $tools, $i);

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
     * Una pasada contra la API. Nunca ejecuta una tool: solo mira qué pidió el modelo.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @return array{run: int, ms: int, prompt_tokens: int, completion_tokens: int, finish_reason: string, tools: list<string>}|null
     */
    private function correr(string $apiKey, string $model, array $messages, array $tools, int $run): ?array
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

        /** @var list<string> $pedidas */
        $pedidas = collect((array) $response->json('choices.0.message.tool_calls', []))
            ->map(fn (mixed $tc): string => (string) data_get($tc, 'function.name', '?'))
            ->all();

        $fila = [
            'run' => $run,
            'ms' => $ms,
            'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            'finish_reason' => (string) $response->json('choices.0.finish_reason', '?'),
            'tools' => $pedidas,
        ];

        $marca = in_array(self::TOOL_ESPERADA, $pedidas, true) ? '<info>✔</info>' : '<comment>✗</comment>';

        $this->line(sprintf(
            '  %-4s %10s %14s %12s  %s %s',
            $run,
            number_format($ms, 0, ',', '.'),
            number_format($fila['prompt_tokens'], 0, ',', '.'),
            number_format($fila['completion_tokens'], 0, ',', '.'),
            $marca,
            $pedidas === [] ? '(ninguna)' : implode(', ', $pedidas),
        ));

        return $fila;
    }

    /**
     * Las filas del store hasta la última `user` inclusive: ese es el turno a regenerar, así que la
     * respuesta del closer que vino después NO viaja.
     *
     * @return list<stdClass>|null
     */
    private function contextRows(int $conversationId): ?array
    {
        $storeIds = DB::table('agent_conversations')
            ->where('user_id', $conversationId)
            ->pluck('id');

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
    private function resumenDeEntrada(int $conversationId, string $system, array $rows, array $tools): void
    {
        /** @var list<string> $nombres */
        $nombres = collect($tools)->map(fn (mixed $t): string => (string) data_get($t, 'function.name', '?'))->all();

        $this->newLine();
        $this->line("  conversación  <options=bold>#{$conversationId}</>");
        $this->line('  prompt        '.number_format(mb_strlen($system), 0, ',', '.').' caracteres'
            .($this->option('prompt-id') !== null ? ' (id '.$this->option('prompt-id').')' : ' (versión activa)'));
        $this->line('  contexto      '.count($rows).' filas del store');
        $this->line('  tools         '.implode(', ', $nombres));
        $this->newLine();
    }

    /**
     * @param  list<array{run: int, ms: int, prompt_tokens: int, completion_tokens: int, finish_reason: string, tools: list<string>}>  $resultados
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

        $this->line("  corridas ............. {$total}");
        $this->line("  llamó la tool ........ {$conLaTool}/{$total}  (".round($conLaTool * 100 / $total).' %)');
        $this->line('  otras tools .......... '.($otras->isEmpty() ? '(ninguna)' : $otras->implode(', ')));
        $this->line('  latencia ............. '.$this->tramo($ms->all(), 1000, ' s'));
        $this->line('  completion_tokens .... '.$this->tramo($tok->all(), 1, ''));
        $this->newLine();

        $this->line($conLaTool === $total
            ? '  → El camino correcto se tomó en todas las corridas.'
            : '  → El camino NO es determinista: hay corridas que se saltean la tool.');
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
     * @param  list<array{run: int, ms: int, prompt_tokens: int, completion_tokens: int, finish_reason: string, tools: list<string>}>  $resultados
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
