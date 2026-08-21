<?php

use App\Models\AgentPrompt;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteAlternative;
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
    config()->set('quotes.medios_de_pago_ofrecibles', ['tarjeta', 'tarjeta-cbu', 'todos']);
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
 * El catálogo contra el que se juzga la elección, con la forma del caso real: el cliente pidió
 * comparar Terceros Completos y Todo Riesgo, y hay una trampa — una Todo Riesgo más barata pero
 * pagable solo con cupón, que el checkout no puede cobrar.
 *
 * @return array{quote: Quote, c: int, ca: int, d: int, cupon: int}
 */
function catalogoDePrueba(int $conversationId): array
{
    $quote = Quote::factory()->create(['conversation_id' => $conversationId]);

    $alt = fn (string $titulo, string $grade, float $precio, string $pago): QuoteAlternative => QuoteAlternative::factory()
        ->create([
            'quote_id' => $quote->id,
            'aseguradora' => 'Galicia',
            'titulo' => $titulo,
            'normalized_grade' => $grade,
            'precio' => $precio,
            'payment_method_id' => $pago,
        ]);

    return [
        'quote' => $quote,
        'c' => $alt('C80', 'third_party_complete', 71119, 'tarjeta')->id,
        'ca' => $alt('C Clima', 'third_party_complete_plus', 80984, 'tarjeta')->id,
        'd' => $alt('Todo Riesgo 4%', 'all_risk', 107274, 'tarjeta')->id,
        'cupon' => $alt('Todo Riesgo Cupón', 'all_risk', 99000, 'cupon')->id,
    ];
}

/**
 * Un store con la forma real del turno de presentación: el usuario pide, el agente de cotización
 * llama `get_quote` y recibe el payload, entra el turno sintético del closer, y el closer responde.
 * Esa última fila es la que la sonda tiene que regenerar, así que NO debe viajar.
 */
function storeDePresentacion(int $conversationId, int $quoteId = 19, string $payloadDeQuote = 'PAYLOAD-DEL-GLOSARIO'): void
{
    $storeId = (string) Str::uuid();

    // `user_id` va en NULL a propósito: así viene en producción para las conversaciones 21, 22 y
    // 23. Por eso el store se resuelve por `agent_conversation_messages.user_id`, que sí está
    // completo, y no por esta tabla.
    DB::table('agent_conversations')->insert([
        'id' => $storeId,
        'user_id' => null,
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
            [['id' => 'call_1', 'name' => 'GetQuoteTool', 'arguments' => ['quoteId' => $quoteId]]],
            [['id' => 'call_1', 'name' => 'GetQuoteTool', 'arguments' => ['quoteId' => $quoteId], 'result' => $payloadDeQuote]],
        ),
        $fila('user', 'Cotizaciones listas'),
        $fila('assistant', 'RESPUESTA DEL CLOSER QUE HAY QUE REGENERAR'),
    ]);
}

/** Respuesta con forma de DeepSeek. `$tools` son los nombres que el modelo dice querer llamar. */
function respuestaDeTurno(array $tools = ['PresentQuoteOptionsTool'], array $argumentos = []): array
{
    return [
        'choices' => [[
            'message' => [
                'content' => 'texto',
                'tool_calls' => array_map(fn (string $n): array => [
                    'id' => 'call_x',
                    'type' => 'function',
                    'function' => [
                        'name' => $n,
                        'arguments' => json_encode($n === 'PresentQuoteOptionsTool' ? $argumentos : []),
                    ],
                ], $tools),
            ],
            'finish_reason' => $tools === [] ? 'stop' : 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 32000, 'completion_tokens' => 4000],
    ];
}

/**
 * Una elección con la forma que la tool exige, anidada bajo `schema_definition` — que es como la
 * devuelve el modelo, porque el SDK envuelve el schema de cada tool en un `ObjectSchema` con ese
 * nombre. Leerla un nivel más arriba da todo inválido.
 */
function eleccion(int $recomendada, int $otra, string $razon1 = 'Cubre lo importante al mejor precio.', string $razon2 = 'Suma daños propios con franquicia.'): array
{
    return ['schema_definition' => [
        'quote_id' => 1,
        'alternative_ids' => [$recomendada, $otra],
        'recommended_alternative_id' => $recomendada,
        'recommended_reason' => $razon1,
        'alternative_reason' => $razon2,
    ]];
}

function correrSonda(int $conversationId, array $opciones = []): PendingCommand
{
    return test()->artisan('ai:probe-presentation', array_merge([
        '--conversation' => $conversationId,
        '--runs' => 1,
    ], $opciones));
}

/** Escenario completo: catálogo, store apuntando a esa quote, y prompt activo. */
function escenario(): array
{
    $conversation = Conversation::factory()->create();
    $cat = catalogoDePrueba($conversation->id);
    storeDePresentacion($conversation->id, $cat['quote']->id);
    promptDeCloser('checkout_closer', 'PROMPT');

    return [$conversation, $cat];
}

/**
 * Lo que hace útil a la sonda es medir el prompt REAL: el propio más los bloques compartidos, en el
 * mismo orden que `AgentPrompt::compose()`. Midiendo solo el del agente subestimaría 6.491 caracteres.
 */
it('manda como system el prompt compuesto con sus bloques compartidos', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    $conversation = Conversation::factory()->create();
    $cat = catalogoDePrueba($conversation->id);
    storeDePresentacion($conversation->id, $cat['quote']->id);

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
    $cat = catalogoDePrueba($conversation->id);
    storeDePresentacion($conversation->id, $cat['quote']->id);

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
    $cat = catalogoDePrueba($conversation->id);
    storeDePresentacion($conversation->id, $cat['quote']->id, 'GLOSARIO-CON-LAS-COBERTURAS');
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

    [$conversation] = escenario();

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

    [$conversation] = escenario();

    correrSonda($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $nombres = array_column(array_column($request['tools'], 'function'), 'name');

        return $request['tool_choice'] === 'auto'
            && ! array_key_exists('temperature', $request->data())
            && count($nombres) === 5
            && in_array('PresentQuoteOptionsTool', $nombres, true)
            && in_array('CheckoutTool', $nombres, true)
            && in_array('CheckCoverageRuleTool', $nombres, true)
            && in_array('RevertStageTool', $nombres, true)
            && in_array('SiniestroGuidanceTool', $nombres, true);
    });
});

/** Sin `--model` sale el tier `smartest`, que es el que declara CheckoutAgent con #[UseSmartestModel]. */
it('usa el tier smartest por defecto y lo puede sobreescribir con --model', function (?string $opcion, string $esperado) {
    Http::fake(['*/chat/completions' => Http::response(respuestaDeTurno())]);

    [$conversation] = escenario();

    correrSonda($conversation->id, $opcion === null ? [] : ['--model' => $opcion])->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['model'] === $esperado);
})->with([
    'por defecto, el smartest' => [null, 'deepseek-v4-pro'],
    'sobreescrito' => ['deepseek-v4-flash', 'deepseek-v4-flash'],
]);

/**
 * Sin resolver los argumentos contra el catálogo, la sonda solo puede decir si llamó la tool — no
 * si eligió bien, que es la pregunta cuando se compara un modelo que razona menos.
 */
it('resuelve las alternativas elegidas contra el catálogo', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d']))
    )]);

    // Una sola subcadena por línea: `expectsOutputToContain` consume una por escritura, así que
    // dos expectativas de la misma línea nunca matchean las dos.
    correrSonda($conversation->id)
        ->expectsOutputToContain('★Galicia C Clima $81,0K (C+A) / Galicia Todo Riesgo 4% $107,3K (D)')
        ->expectsOutputToContain('presentaciones válidas ... 1/1')
        ->assertSuccessful();
});

/**
 * Regresión del bug que dio 0/10 válidas en las dos primeras corridas reales: los argumentos venían
 * anidados bajo `schema_definition` y la sonda los leía un nivel más arriba, así que reportaba
 * `cantidad`, `recomendada_fuera`, `razon_vacia` y `grades_no_pedidos` en corridas que en realidad
 * eran perfectas. Se acepta la forma plana también, por si el envoltorio del SDK cambia.
 */
it('acepta los argumentos planos igual que anidados bajo schema_definition', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d'])['schema_definition'])
    )]);

    correrSonda($conversation->id)
        ->expectsOutputToContain('presentaciones válidas ... 1/1')
        ->assertSuccessful();
});

/** El cliente pidió una de cada nivel: dos del mismo grado no es la presentación que se le prometió. */
it('marca inválida la corrida que eligió dos alternativas del mismo grado', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['c'], $cat['ca']))
    )]);

    correrSonda($conversation->id)
        ->expectsOutputToContain('grades_no_pedidos')
        ->expectsOutputToContain('presentaciones válidas ... 0/1')
        ->assertSuccessful();
});

/** Ofrecer algo que el checkout no puede cobrar es una venta rota, aunque el grado sea el correcto. */
it('marca inválida la corrida que eligió una alternativa no ofrecible', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['cupon']))
    )]);

    correrSonda($conversation->id)
        ->expectsOutputToContain('no_ofrecible')
        ->expectsOutputToContain('presentaciones válidas ... 0/1')
        ->assertSuccessful();
});

/** Las razones son load-bearing en la vista pública: sin ellas la card queda hueca. */
it('marca inválida la corrida con una razón vacía', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d'], razon2: '   '))
    )]);

    correrSonda($conversation->id)
        ->expectsOutputToContain('razon_vacia')
        ->assertSuccessful();
});

/**
 * El dato central de la comparación entre tiers: un modelo que delibera menos puede llamar la tool
 * igual y dispersarse en la elección. El par se ordena para que el mismo par en distinto orden
 * cuente como la misma elección.
 */
it('cuenta la distribución de pares elegidos', function () {
    [$conversation, $cat] = escenario();

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d'])))
            ->push(respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['d'], $cat['ca'])))
            ->push(respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['c'], $cat['d']))),
    ]);

    $par = min($cat['ca'], $cat['d']).'+'.max($cat['ca'], $cat['d']);

    correrSonda($conversation->id, ['--runs' => 3])
        ->expectsOutputToContain("{$par} ×2")
        ->assertSuccessful();
});

/** Las razones son prosa y no se pueden contar: el volcado las lleva completas para leerlas. */
it('vuelca las razones completas al json', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d'], 'RAZON DE LA RECOMENDADA', 'RAZON DE LA OTRA'))
    )]);

    $ruta = tempnam(sys_get_temp_dir(), 'probe').'.json';

    correrSonda($conversation->id, ['--json' => $ruta])->assertSuccessful();

    $volcado = (string) file_get_contents($ruta);

    expect($volcado)
        ->toContain('RAZON DE LA RECOMENDADA')
        ->toContain('RAZON DE LA OTRA');

    unlink($ruta);
});

/**
 * Sin esto la sonda no sirve para nada: tiene que distinguir la corrida que tomó el camino correcto
 * de la que llamó otra tool y de la que no llamó ninguna.
 */
it('cuenta cuántas corridas tomaron el camino correcto', function () {
    [$conversation, $cat] = escenario();

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d'])))
            ->push(respuestaDeTurno(['CheckCoverageRuleTool']))
            ->push(respuestaDeTurno([])),
    ]);

    correrSonda($conversation->id, ['--runs' => 3])
        ->expectsOutputToContain('1/3')
        ->expectsOutputToContain('CheckCoverageRuleTool ×1')
        ->expectsOutputToContain('NO es determinista')
        ->assertSuccessful();
});

it('avisa cuando todas las corridas eligieron bien', function () {
    [$conversation, $cat] = escenario();

    Http::fake(['*/chat/completions' => Http::response(
        respuestaDeTurno(['PresentQuoteOptionsTool'], eleccion($cat['ca'], $cat['d']))
    )]);

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
