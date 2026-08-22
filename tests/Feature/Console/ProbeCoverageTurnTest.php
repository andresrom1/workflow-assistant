<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
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
        'default' => 'deepseek-v4-default',
        'cheapest' => 'deepseek-v4-flash',
        'smartest' => 'deepseek-v4-pro',
    ]);
});

function promptDeCobertura(string $key, string $content): void
{
    AgentPrompt::create([
        'agent_key' => $key,
        'type' => str_starts_with($key, 'shared_') ? 'shared' : 'agent',
        'content' => $content,
        'version' => 1,
        'is_active' => true,
        'status' => AgentPrompt::STATUS_ACTIVE,
    ]);
}

/**
 * Un store con la forma real: el turno de cobertura ocurre en el medio, y después vienen el turno
 * sintético del closer y su respuesta. El corte tiene que caer en "terceros completos y todo
 * riesgo" y no en la última `user` de la conversación, que es "Cotizaciones listas".
 */
function storeConTurnoDeCobertura(int $conversationId): void
{
    $storeId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $storeId, 'user_id' => null, 'title' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fila = fn (string $agente, string $role, string $content, array $calls = []): array => [
        'id' => (string) Str::orderedUuid(),
        'conversation_id' => $storeId,
        'user_id' => $conversationId,
        'agent' => "App\\AI\\Agents\\{$agente}",
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => json_encode($calls),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('agent_conversation_messages')->insert([
        $fila('VehicleIdentifierAgent', 'user', 'quiero cotizar un nissan kicks'),
        $fila('VehicleIdentifierAgent', 'assistant', '¿Qué cobertura te interesa?'),
        $fila('CoveragePreferenceAgent', 'user', 'terceros completos y todo riesgo'),
        $fila('CoveragePreferenceAgent', 'assistant', 'RESPUESTA DE COBERTURA A REGENERAR', [
            ['id' => 'call_c', 'name' => 'CoveragePreferenceTool', 'arguments' => [
                'coverage_code' => 'D', 'coverage_codes' => ['C', 'D'], 'patente' => 'AD415WE',
            ]],
        ]),
        $fila('QuoteAgent', 'user', 'Las cotizaciones ya están listas.'),
        $fila('CheckoutAgent', 'user', 'Cotizaciones listas'),
        $fila('CheckoutAgent', 'assistant', 'PRESENTACION DEL CLOSER'),
    ]);
}

function respuestaConTexto(string $texto, array $tools = []): array
{
    return [
        'choices' => [[
            'message' => [
                'content' => $texto,
                'reasoning_content' => 'El cliente pidió comparar C y D.',
                'tool_calls' => array_map(fn (string $n): array => [
                    'id' => 'call_'.$n, 'type' => 'function', 'function' => ['name' => $n, 'arguments' => '{}'],
                ], $tools),
            ],
            'finish_reason' => $tools === [] ? 'stop' : 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 15400, 'completion_tokens' => 90],
    ];
}

/**
 * Cada corrida son DOS llamadas: primero el modelo llama la tool por su cuenta, después escribe el
 * texto con el resultado que le inyectamos. Inventar el mensaje del assistant no serviría — la API
 * lo rechaza si no vuelve su `reasoning_content`.
 */
function fakeDelTurno(string $texto, int $corridas = 1): void
{
    $seq = Http::sequence();

    for ($i = 0; $i < $corridas; $i++) {
        $seq->push(respuestaConTexto('', ['CoveragePreferenceTool']))->push(respuestaConTexto($texto));
    }

    Http::fake(['*/chat/completions' => $seq]);
}

function correrSondaCobertura(int $conversationId, array $opciones = []): PendingCommand
{
    return test()->artisan('ai:probe-coverage-turn', array_merge([
        '--conversation' => $conversationId,
        '--runs' => 1,
    ], $opciones));
}

function escenarioDeCobertura(): Conversation
{
    $conversation = Conversation::factory()->create();
    storeConTurnoDeCobertura($conversation->id);
    promptDeCobertura('coverage_preference', 'PROMPT DE COBERTURA');

    return $conversation;
}

it('manda como system el prompt de cobertura compuesto con sus bloques compartidos', function () {
    fakeDelTurno('Dale.');

    $conversation = Conversation::factory()->create();
    storeConTurnoDeCobertura($conversation->id);

    promptDeCobertura('shared_style', 'ESTILO');
    promptDeCobertura('shared_grounding', 'GROUNDING');
    promptDeCobertura('shared_siniestro', 'SINIESTRO');
    promptDeCobertura('coverage_preference', 'PROMPT DE COBERTURA');

    correrSondaCobertura($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $system = $request['messages'][0];

        return $system['role'] === 'system'
            && str_contains($system['content'], 'ESTILO')
            && str_contains($system['content'], 'PROMPT DE COBERTURA');
    });
});

/**
 * El corte es lo que distingue esta sonda de la del closer: la última `user` de la conversación es
 * "Cotizaciones listas", pero el turno que interesa termina tres filas antes.
 */
it('corta en el turno de cobertura y no en la última user de la conversación', function () {
    fakeDelTurno('Dale.');

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $crudo = (string) json_encode($request['messages']);

        return str_contains($crudo, 'terceros completos y todo riesgo')
            && ! str_contains($crudo, 'Cotizaciones listas')
            && ! str_contains($crudo, 'RESPUESTA DE COBERTURA A REGENERAR')
            && ! str_contains($crudo, 'PRESENTACION DEL CLOSER');
    });
});

/**
 * Lo único sintético es el RESULTADO de la tool. La llamada y el `reasoning_content` son los que
 * devolvió el modelo en el paso 1: inventarlos hace que la API rechace la request con *"The
 * `reasoning_content` in the thinking mode must be passed back to the API"*, y además cambiaría el
 * contexto que estamos midiendo.
 */
it('devuelve la llamada del modelo con su reasoning y sustituye solo el resultado', function () {
    fakeDelTurno('Dale.');

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id)->assertSuccessful();

    // La segunda request es la continuación: lleva el assistant del modelo y el tool result nuestro.
    $requests = [];
    Http::assertSent(function ($request) use (&$requests) {
        $requests[] = $request['messages'];

        return true;
    });

    $continuacion = collect($requests[1]);
    $assistant = $continuacion->last(fn ($m): bool => ($m['role'] ?? null) === 'assistant');
    $tool = $continuacion->last(fn ($m): bool => ($m['role'] ?? null) === 'tool');

    expect($assistant['tool_calls'][0]['function']['name'])->toBe('CoveragePreferenceTool')
        ->and($assistant['reasoning_content'])->toBe('El cliente pidió comparar C y D.')
        ->and($tool['tool_call_id'])->toBe('call_CoveragePreferenceTool')
        ->and($tool['content'])->toContain("Preferencia 'D' guardada para AD415WE");
});

/** Si el modelo no llama la tool, no hay segundo paso que dar — se registra y se sigue. */
it('registra la corrida en la que el modelo no llamó la tool', function () {
    Http::fake(['*/chat/completions' => Http::response(respuestaConTexto('¿Qué cobertura preferís?'))]);

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id)
        ->expectsOutputToContain('no llamó CoveragePreferenceTool')
        ->expectsOutputToContain('llamó la tool ........ 0/1')
        ->assertSuccessful();
});

/**
 * El texto por defecto sale de la constante que usa el adapter, así que no puede desincronizarse
 * de producción — que es justo lo que la sonda tiene que reproducir.
 */
it('usa por defecto el pedido de aviso que manda el adapter en producción', function () {
    fakeDelTurno('Dale.');

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        // El guard no es cosmético: `assertSent` corre contra TODAS las requests, y la del paso 1
        // todavía no tiene mensaje `tool`.
        $tool = collect($request['messages'])->firstWhere('role', 'tool');

        return $tool !== null && str_contains($tool['content'], WhatsAppAdapter::PEDIDO_DE_AVISO);
    });
});

/** El punto del flag: probar otra redacción sin desplegar nada. */
it('permite inyectar un tool_output propio', function () {
    fakeDelTurno('Dale.');

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id, ['--tool-output' => 'REDACCION ALTERNATIVA'])->assertSuccessful();

    Http::assertSent(function ($request) {
        $tool = collect($request['messages'])->firstWhere('role', 'tool');

        return $tool !== null
            && $tool['content'] === 'REDACCION ALTERNATIVA'
            && ! str_contains($tool['content'], WhatsAppAdapter::PEDIDO_DE_AVISO);
    });
});

/**
 * `CoveragePreferenceAgent` declara `#[UseCheapestModel]`. Medir con el modelo equivocado daría un
 * texto y una latencia que no son los del turno real.
 */
it('usa el tier cheapest que declara el agente y ofrece sus 5 tools', function () {
    fakeDelTurno('Dale.');

    $conversation = escenarioDeCobertura();

    correrSondaCobertura($conversation->id)->assertSuccessful();

    Http::assertSent(function ($request) {
        $nombres = array_column(array_column($request['tools'], 'function'), 'name');

        return $request['model'] === 'deepseek-v4-flash'
            && $request['tool_choice'] === 'auto'
            && ! array_key_exists('temperature', $request->data())
            && count($nombres) === 5
            && in_array('CoveragePreferenceTool', $nombres, true)
            && in_array('ProvideVehicleFactTool', $nombres, true);
    });
});

/** Lo único que importa medir acá es el texto — sin capturarlo la sonda no contesta nada. */
it('imprime el texto de cada corrida y lo vuelca al json', function () {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(respuestaConTexto('', ['CoveragePreferenceTool']))
            ->push(respuestaConTexto('Dale, te cotizo las dos. En cuanto las tenga te las paso.'))
            ->push(respuestaConTexto('', ['CoveragePreferenceTool']))
            ->push(respuestaConTexto('Listo, tomo nota.')),
    ]);

    $conversation = escenarioDeCobertura();
    $ruta = tempnam(sys_get_temp_dir(), 'cobertura').'.json';

    correrSondaCobertura($conversation->id, ['--runs' => 2, '--json' => $ruta])
        ->expectsOutputToContain('En cuanto las tenga te las paso')
        ->assertSuccessful();

    expect((string) file_get_contents($ruta))
        ->toContain('En cuanto las tenga te las paso')
        ->toContain('Listo, tomo nota');

    unlink($ruta);
});

it('sale con error si la conversación no tiene turno de cobertura en el store', function () {
    Http::fake();

    $conversation = Conversation::factory()->create();
    promptDeCobertura('coverage_preference', 'PROMPT');

    correrSondaCobertura($conversation->id)->assertFailed();

    Http::assertNothingSent();
});

it('sale con error si no hay prompt activo de cobertura', function () {
    Http::fake();

    $conversation = Conversation::factory()->create();
    storeConTurnoDeCobertura($conversation->id);

    correrSondaCobertura($conversation->id)->assertFailed();

    Http::assertNothingSent();
});
