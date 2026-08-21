<?php

use App\Models\AgentPrompt;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.providers.deepseek.key', 'sk-test');
    config()->set('ai.providers.deepseek.models.text', [
        'default' => 'deepseek-v4-flash',
        'cheapest' => 'deepseek-v4-flash',
        'smartest' => 'deepseek-v4-pro',
    ]);
});

function promptDeCloser(string $key, string $content, bool $activo = true): AgentPrompt
{
    return AgentPrompt::create([
        'agent_key' => $key,
        'type' => str_starts_with($key, 'shared_') ? 'shared' : 'agent',
        'content' => $content,
        'version' => 1,
        'is_active' => $activo,
        'status' => $activo ? AgentPrompt::STATUS_ACTIVE : AgentPrompt::STATUS_ARCHIVED,
    ]);
}

/**
 * Un store con la forma real del turno de presentación: el usuario pide, el agente de cotización
 * llama `get_quote` y recibe el payload, entra el turno sintético del closer, y el closer responde.
 * Esa última fila es la que la sonda tiene que regenerar, así que NO debe viajar.
 */
function storeDePresentacion(int $conversationId, string $payloadDeQuote = 'PAYLOAD-DEL-GLOSARIO'): void
{
    $storeId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $storeId,
        'user_id' => $conversationId,
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fila = fn (string $role, string $content, array $calls = [], array $results = []): array => [
        'id' => (string) Str::orderedUuid(),
        'conversation_id' => $storeId,
        'user_id' => $conversationId,
        'agent' => 'App\\AI\\Agents\\CheckoutAgent',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => json_encode($calls),
        'tool_results' => json_encode($results),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('agent_conversation_messages')->insert([
        $fila('user', 'terceros completos y todo riesgo'),
        $fila(
            'assistant',
            'Texto del QuoteAgent que se descarta',
            [['id' => 'call_1', 'name' => 'GetQuoteTool', 'arguments' => ['quoteId' => 19]]],
            [['id' => 'call_1', 'name' => 'GetQuoteTool', 'arguments' => ['quoteId' => 19], 'result' => $payloadDeQuote]],
        ),
        $fila('user', 'Cotizaciones listas'),
        $fila('assistant', 'RESPUESTA DEL CLOSER QUE HAY QUE REGENERAR'),
    ]);
}

/** Respuesta con forma de DeepSeek. `$tools` son los nombres que el modelo dice querer llamar. */
function respuestaDeTurno(array $tools = ['PresentQuoteOptionsTool']): array
{
    return [
        'choices' => [[
            'message' => [
                'content' => 'texto',
                'tool_calls' => array_map(fn (string $n): array => [
                    'id' => 'call_x',
                    'type' => 'function',
                    'function' => ['name' => $n, 'arguments' => '{}'],
                ], $tools),
            ],
            'finish_reason' => $tools === [] ? 'stop' : 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 32000, 'completion_tokens' => 4000],
    ];
}

function correrSonda(int $conversationId, array $opciones = []): PendingCommand
{
    return test()->artisan('ai:probe-presentation', array_merge([
        '--conversation' => $conversationId,
        '--runs' => 1,
    ], $opciones));
}

/**
 * Lo que hace útil a la sonda es medir el prompt REAL: el propio más los bloques compartidos, en el
 * mismo orden que `AgentPrompt::compose()`. Midiendo solo el del agente subestimaría 6.491 caracteres.
 */
it('manda como system el prompt compuesto con sus bloques compartidos', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);

    promptDeCloser('shared_style', 'ESTILO');
    promptDeCloser('shared_grounding', 'GROUNDING');
    promptDeCloser('shared_siniestro', 'SINIESTRO');
    promptDeCloser('checkout_closer', 'PROMPT DEL CLOSER');

    correrSonda($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $system = $request['messages'][0];

        return $system['role'] === 'system'
            && str_contains($system['content'], 'ESTILO')
            && str_contains($system['content'], 'GROUNDING')
            && str_contains($system['content'], 'SINIESTRO')
            && str_contains($system['content'], 'PROMPT DEL CLOSER');
    });
});

it('pinea una versión histórica del prompt con --prompt-id', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);

    promptDeCloser('shared_style', 'ESTILO');
    $vieja = promptDeCloser('checkout_closer', 'PROMPT VIEJO', activo: false);
    promptDeCloser('checkout_closer', 'PROMPT NUEVO');

    correrSonda($conversation->id, ['--prompt-id' => $vieja->id])->assertSuccessful();

    Http::assertSent(function ($request) {
        $system = $request['messages'][0]['content'];

        return str_contains($system, 'PROMPT VIEJO')
            && ! str_contains($system, 'PROMPT NUEVO')
            && str_contains($system, 'ESTILO');
    });
});

/**
 * La prueba central: el resultado de `get_quote` tiene que estar en el contexto. Es justo lo que el
 * Studio pierde al reconstruir desde la tabla `messages`, y es la variable que se está estudiando.
 */
it('reconstruye el tool call y su resultado, con el payload de get_quote adentro', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id, 'GLOSARIO-CON-LAS-COBERTURAS');
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $roles = array_column($request['messages'], 'role');
        $tool = collect($request['messages'])->firstWhere('role', 'tool');
        $assistant = collect($request['messages'])->firstWhere('role', 'assistant');

        return in_array('tool', $roles, true)
            && $tool['content'] === 'GLOSARIO-CON-LAS-COBERTURAS'
            && $tool['tool_call_id'] === 'call_1'
            && $assistant['tool_calls'][0]['function']['name'] === 'GetQuoteTool';
    });
});

/** El turno a regenerar es el último `user`; la respuesta que vino después no puede viajar. */
it('corta el contexto en el último mensaje de usuario', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $messages = $request['messages'];
        $ultimo = end($messages);
        $crudo = json_encode($messages);

        return $ultimo['role'] === 'user'
            && str_contains((string) json_encode($ultimo), 'Cotizaciones listas')
            && ! str_contains((string) $crudo, 'RESPUESTA DEL CLOSER QUE HAY QUE REGENERAR');
    });
});

/**
 * Las cinco tools del closer, con `tool_choice: auto` y SIN `temperature`: producción no manda
 * ninguna porque CheckoutAgent no declara `#[Temperature]`. Mandarla mediría otra cosa.
 */
it('ofrece las 5 tools del closer con tool_choice auto y sin temperature', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $nombres = array_column(array_column($request['tools'], 'function'), 'name');

        return $request['tool_choice'] === 'auto'
            && $request['model'] === 'deepseek-v4-pro'
            && ! array_key_exists('temperature', $request->data())
            && count($nombres) === 5
            && in_array('PresentQuoteOptionsTool', $nombres, true)
            && in_array('CheckoutTool', $nombres, true)
            && in_array('CheckCoverageRuleTool', $nombres, true)
            && in_array('RevertStageTool', $nombres, true)
            && in_array('SiniestroGuidanceTool', $nombres, true);
    });
});

/**
 * Sin esto la sonda no sirve para nada: tiene que distinguir la corrida que tomó el camino correcto
 * de la que llamó otra tool y de la que no llamó ninguna.
 */
it('cuenta cuántas corridas tomaron el camino correcto', function () {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(respuestaDeTurno(['PresentQuoteOptionsTool']))
            ->push(respuestaDeTurno(['CheckCoverageRuleTool']))
            ->push(respuestaDeTurno([])),
    ]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id, ['--runs' => 3])
        ->expectsOutputToContain('1/3')
        ->expectsOutputToContain('CheckCoverageRuleTool ×1')
        ->expectsOutputToContain('NO es determinista')
        ->assertSuccessful();
});

it('avisa cuando todas las corridas tomaron el camino correcto', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id, ['--runs' => 2])
        ->expectsOutputToContain('2/2')
        ->expectsOutputToContain('todas las corridas')
        ->assertSuccessful();
});

it('sale con error si la conversación no tiene store asociado', function () {
    Http::fake();

    $conversation = Conversation::factory()->create();
    promptDeCloser('checkout_closer', 'PROMPT');

    correrSonda($conversation->id)->assertFailed();

    Http::assertNothingSent();
});

it('sale con error si no hay prompt activo para el closer', function () {
    Http::fake();

    $conversation = Conversation::factory()->create();
    storeDePresentacion($conversation->id);

    correrSonda($conversation->id)->assertFailed();

    Http::assertNothingSent();
});
