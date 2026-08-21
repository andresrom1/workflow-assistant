<?php

use App\Models\AgentPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.providers.deepseek.key', 'sk-test');
    config()->set('ai.providers.deepseek.models.text', [
        'default' => 'deepseek-v4-flash',
        'cheapest' => 'deepseek-v4-flash',
        'smartest' => 'deepseek-v4-pro',
    ]);
});

function promptActivo(string $key, string $content): void
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

/** Respuestas en orden: miss forzado, repetición (hit) y prompt tal cual. */
function respuestasDeSonda(int $hitEnLaTercera = 0): void
{
    $usos = [
        ['prompt_tokens' => 1000, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 1000],
        ['prompt_tokens' => 1000, 'prompt_cache_hit_tokens' => 960, 'prompt_cache_miss_tokens' => 40],
        ['prompt_tokens' => 990, 'prompt_cache_hit_tokens' => $hitEnLaTercera, 'prompt_cache_miss_tokens' => 990 - $hitEnLaTercera],
    ];

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(['usage' => $usos[0], 'choices' => []])
            ->push(['usage' => $usos[1], 'choices' => []])
            ->push(['usage' => $usos[2], 'choices' => []]),
    ]);
}

/**
 * Lo que hace útil a la sonda es medir el prompt REAL: el propio más los bloques compartidos, tal
 * como los compone el runtime. Si midiera solo el `.md` del agente, subestimaría 6.491 caracteres.
 */
it('manda como system el prompt compuesto con sus bloques compartidos', function () {
    respuestasDeSonda();

    promptActivo('shared_style', 'ESTILO COMPARTIDO');
    promptActivo('shared_grounding', 'GROUNDING COMPARTIDO');
    promptActivo('shared_siniestro', 'SINIESTRO COMPARTIDO');
    promptActivo('checkout_closer', 'PROMPT DEL CLOSER');

    $this->artisan('ai:probe-cache', ['agent' => 'checkout_closer'])->assertSuccessful();

    Http::assertSent(function ($request) {
        $system = $request['messages'][0]['content'];

        return str_contains($system, 'ESTILO COMPARTIDO')
            && str_contains($system, 'GROUNDING COMPARTIDO')
            && str_contains($system, 'SINIESTRO COMPARTIDO')
            && str_contains($system, 'PROMPT DEL CLOSER');
    });
});

/**
 * El ruido va al PRINCIPIO y solo en las dos primeras: la caché es por prefijo, así que ensuciar el
 * final no rompería nada y la primera llamada pegaría hit igual, falseando el delta.
 */
it('ensucia el prefijo solo en las dos primeras llamadas', function () {
    respuestasDeSonda();
    promptActivo('checkout_closer', 'PROMPT DEL CLOSER');

    $this->artisan('ai:probe-cache')->assertSuccessful();

    $systems = [];
    Http::assertSent(function ($request) use (&$systems) {
        $systems[] = $request['messages'][0]['content'];

        return true;
    });

    expect($systems)->toHaveCount(3)
        ->and($systems[0])->toStartWith('[')
        ->and($systems[0])->toBe($systems[1])
        ->and($systems[2])->toStartWith('PROMPT DEL CLOSER');
});

/**
 * El tier sale del atributo de la clase, no de un mapa aparte: CheckoutAgent lleva
 * `#[UseSmartestModel]` y QuoteAgent `#[UseCheapestModel]`, y el prefill no cuesta lo mismo en los
 * dos modelos — medir con el equivocado daría un número que no es el del turno real.
 */
it('usa el modelo del tier que declara el agente', function (string $key, string $modelo) {
    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1000, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 1000],
            'choices' => [],
        ]),
    ]);
    promptActivo($key, 'PROMPT');

    $this->artisan('ai:probe-cache', ['agent' => $key])->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['model'] === $modelo);
})->with([
    'closer usa el smartest' => ['checkout_closer', 'deepseek-v4-pro'],
    'quote usa el cheapest' => ['quote_reception', 'deepseek-v4-flash'],
]);

/** `max_tokens = 1` es lo que hace que el tiempo de pared sea casi todo prefill. */
it('pide una sola ficha de salida para que el tiempo sea prefill', function () {
    respuestasDeSonda();
    promptActivo('checkout_closer', 'X');

    $this->artisan('ai:probe-cache')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['max_tokens'] === 1);
});

it('mide el archivo cuando se lo pasan y no toca la base', function () {
    respuestasDeSonda();

    $ruta = tempnam(sys_get_temp_dir(), 'prompt').'.md';
    file_put_contents($ruta, 'PROMPT DE PRODUCCION');

    $this->artisan('ai:probe-cache', ['agent' => 'checkout_closer', '--file' => $ruta])->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request['messages'][0]['content'], 'PROMPT DE PRODUCCION'));

    unlink($ruta);
});

/**
 * Si el miss forzado pega caché, el delta compara dos hits y da casi cero — reportarlo como
 * "el prompt no cuesta latencia" sería exactamente la conclusión equivocada.
 */
it('falla en vez de reportar un delta falso si el miss forzado pegó caché', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1000, 'prompt_cache_hit_tokens' => 1000, 'prompt_cache_miss_tokens' => 0],
            'choices' => [],
        ]),
    ]);
    promptActivo('checkout_closer', 'X');

    $this->artisan('ai:probe-cache')->assertFailed();
});

it('avisa cuando el prefijo no cachea ni repitiendo la llamada', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'usage' => ['prompt_tokens' => 1000, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 1000],
            'choices' => [],
        ]),
    ]);
    promptActivo('checkout_closer', 'X');

    $this->artisan('ai:probe-cache')
        ->expectsOutputToContain('NO se cacheó')
        ->assertSuccessful();
});

it('sale con error si no hay contenido para ese agente', function () {
    Http::fake();

    $this->artisan('ai:probe-cache', ['agent' => 'checkout_closer'])->assertFailed();

    Http::assertNothingSent();
});
