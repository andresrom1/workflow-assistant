<?php

namespace App\AI\Probes;

use App\Models\AgentPrompt;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
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
use RuntimeException;
use stdClass;

/**
 * Reconstruye un turno histórico como lo mandaría producción: el prompt compuesto, los mensajes del
 * store y las tools del agente.
 *
 * La fidelidad no se traduce a mano — el payload sale de los MISMOS mappers que usa Prism
 * ({@see ToolMap}, {@see MessageMap}) sobre los mismos value objects. Cualquier traducción propia
 * se desincronizaría del SDK sin que nadie se entere.
 */
class TurnRequest
{
    /**
     * El prompt tal como lo compone el runtime, o una versión pineada por id para reevaluar un
     * contexto viejo con el prompt que corría entonces.
     *
     * @param  list<string>  $sharedBlocks
     */
    public static function system(string $agentKey, array $sharedBlocks, ?int $pinnedPromptId = null): string
    {
        if ($pinnedPromptId === null) {
            return AgentPrompt::compose($agentKey, $sharedBlocks);
        }

        $version = AgentPrompt::find($pinnedPromptId);

        if ($version === null) {
            return '';
        }

        // Mismo orden que compose(): los compartidos primero, el del agente al final.
        return collect($sharedBlocks)
            ->map(fn (string $key): ?string => AgentPrompt::activeFor($key)?->content)
            ->push($version->content)
            ->filter()
            ->implode("\n\n");
    }

    /**
     * El uuid del store de una conversación.
     *
     * Se resuelve por `agent_conversation_messages.user_id` y NO por `agent_conversations.user_id`:
     * esa columna viene inconsistente en producción — está en NULL para las conversaciones 21, 22 y
     * 23, y poblada para la 20 y la 24. La de los mensajes está completa, y además es la tabla de
     * la que se leen las filas.
     *
     * @throws RuntimeException
     */
    public static function storeIdFor(int $conversationId): string
    {
        $ids = DB::table('agent_conversation_messages')
            ->where('user_id', $conversationId)
            ->distinct()
            ->pluck('conversation_id');

        if ($ids->count() !== 1) {
            throw new RuntimeException("Se esperaba 1 conversación de agente para la #{$conversationId}, hay {$ids->count()}.");
        }

        return (string) $ids->first();
    }

    /**
     * Las filas del store hasta la última `user` inclusive: ese es el turno a regenerar, así que la
     * respuesta que vino después NO viaja.
     *
     * `$agentSuffix` acota el corte al turno de un agente puntual — las filas vienen etiquetadas con
     * el agente que las manejó, así que `'CoveragePreferenceAgent'` corta en *"terceros completos y
     * todo riesgo"* y no en el turno sintético del closer, que es la última `user` de la conversación.
     *
     * @return list<stdClass>
     *
     * @throws RuntimeException
     */
    public static function rowsUpToLastUser(int $conversationId, ?string $agentSuffix = null): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', self::storeIdFor($conversationId))
            ->orderBy('id')
            ->get()
            ->all();

        $corte = null;

        foreach ($rows as $idx => $row) {
            $delAgente = $agentSuffix === null || str_ends_with((string) $row->agent, $agentSuffix);

            if ($row->role === 'user' && $delAgente) {
                $corte = $idx;
            }
        }

        if ($corte === null) {
            $de = $agentSuffix === null ? '' : " de {$agentSuffix}";

            throw new RuntimeException("La conversación #{$conversationId} no tiene ningún mensaje de usuario{$de} en el store.");
        }

        return array_slice($rows, 0, $corte + 1);
    }

    /**
     * Traduce las filas del store a value objects de Prism, con la misma lógica que
     * {@see DatabaseConversationStore::getLatestConversationMessages()}.
     *
     * Reconstruir `tool_calls` y `tool_results` es lo que hace fieles a las sondas: ahí adentro
     * viajan los payloads de las tools, que suelen ser la variable bajo estudio.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, AssistantMessage|ToolResultMessage|UserMessage>
     */
    public static function prismMessages(array $rows): array
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
     * Continúa un turno después de una tool, sustituyendo SOLO el resultado.
     *
     * El mensaje del assistant se arma con lo que el modelo devolvió de verdad —incluido su
     * `reasoning_content`, que los modelos en modo thinking exigen de vuelta— así que lo único
     * sintético es el resultado de la tool, que es justamente la variable bajo estudio.
     *
     * Inventar ese mensaje no funciona: la API rechaza la request con *"The `reasoning_content` in
     * the thinking mode must be passed back to the API."*
     *
     * @param  array<int, mixed>  $payloadMessages  ya mapeados, tal como se mandaron
     * @param  array{content: string, reasoning_content: string, tool_calls: list<array<string, mixed>>}  $respuesta
     * @return array<int, mixed>
     */
    public static function continueAfterTool(array $payloadMessages, array $respuesta, string $result): array
    {
        $payloadMessages[] = array_filter([
            'role' => 'assistant',
            'content' => $respuesta['content'],
            'reasoning_content' => $respuesta['reasoning_content'],
            'tool_calls' => $respuesta['tool_calls'],
        ], fn (mixed $v): bool => $v !== '' && $v !== []);

        foreach ($respuesta['tool_calls'] as $tc) {
            $payloadMessages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($tc['id'] ?? ''),
                'content' => $result,
            ];
        }

        return $payloadMessages;
    }

    /**
     * @param  array<int, AssistantMessage|ToolResultMessage|UserMessage>  $messages
     * @return array<int, mixed>
     */
    public static function payload(array $messages, string $system): array
    {
        return (new MessageMap($messages, [new SystemMessage($system)]))();
    }

    /**
     * Las tools de un agente, envueltas igual que
     * {@see AddsToolsToPrismRequests::createPrismTool()} y
     * mapeadas con el mapper de Prism.
     *
     * Ninguna tool del proyecto sobreescribe `name()`, así que el nombre que viaja es
     * `class_basename` — el mismo que se ve en `agent_execution_logs.tool_calls`.
     *
     * Instanciarlas es inofensivo: acá solo se leen `description()` y `schema()`; los `handle()`
     * nunca se invocan porque las sondas no corren el tool loop.
     *
     * @param  array<int, Tool>  $tools
     * @return array<array-key, mixed>
     */
    public static function toolPayload(array $tools): array
    {
        return ToolMap::Map(array_map(function (Tool $tool): PrismTool {
            $schema = $tool->schema(new JsonSchemaTypeFactory);

            $prismTool = (new PrismTool)
                ->as(class_basename($tool))
                ->for((string) $tool->description());

            if ($schema !== []) {
                $prismTool = $prismTool->withParameter(new ObjectSchema($schema));
            }

            return $prismTool;
        }, $tools));
    }

    /**
     * El SDK envuelve el schema de cada tool en un `ObjectSchema` llamado `schema_definition`, así
     * que el modelo devuelve los campos anidados ahí adentro. `invokeTool()` los desarma con el
     * mismo `??`; sin esto una sonda lee un nivel más arriba y da todo inválido.
     *
     * @return array<string, mixed>
     */
    public static function unwrapArguments(string $json): array
    {
        /** @var array<string, mixed> $crudos */
        $crudos = (array) json_decode($json, true);

        return (array) ($crudos['schema_definition'] ?? $crudos);
    }
}
