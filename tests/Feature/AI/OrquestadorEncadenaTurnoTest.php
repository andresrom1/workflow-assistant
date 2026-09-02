<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use App\Models\Quote;
use App\Support\MemoriaDelAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * El turno que registra un vehículo cotizable cierra prometiendo las opciones, sin pregunta
 * abierta. Si el cliente ya había dicho la cobertura, el agente no tiene nada que preguntarle
 * y la conversación queda sin nadie esperando un mensaje: la cotización llega, pero
 * `NotifyClientQuoteReady` no la presenta porque `coverage_set` sigue en false. Ver ROADMAP,
 * bitácora 2026-09-02 (conversación 26 en producción).
 *
 * La decisión de encadenar es privada; se invoca por reflexión (patrón aceptado en CLAUDE.md).
 *
 * @param  array<string, bool>  $antes
 * @param  array<string, bool>  $despues
 * @return array{disparador: string, marcar: bool}|null
 */
function cadenaDecididaPara(Conversation $conversation, array $antes, array $despues): ?array
{
    $orchestrator = new InsuranceOrchestrator(Mockery::mock(WhatsAppAdapter::class));
    $metodo = (new ReflectionClass($orchestrator))->getMethod('cadenaAEncadenar');
    $metodo->setAccessible(true);

    return $metodo->invoke($orchestrator, $antes, $despues, $conversation);
}

/**
 * @param  array<string, bool>  $overrides
 * @return array<string, bool>
 */
function flujoEnEstado(array $overrides = []): array
{
    return array_merge([
        'customer_identified' => true,
        'vehicle_identified' => false,
        'coverage_set' => false,
        'quote_ready' => false,
        'checkout_done' => false,
    ], $overrides);
}

it('encadena a la etapa de cobertura cuando la identificación dejó una cotización en vuelo', function () {
    $conversation = Conversation::factory()->create();
    Quote::factory()->create(['conversation_id' => $conversation->id, 'status' => 'pending']);

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(),
        flujoEnEstado(['vehicle_identified' => true]),
    );

    expect($cadena)->not->toBeNull()
        ->and($cadena['disparador'])->toContain('coverage_preference')
        // El texto del VehicleIdentifierAgent se descarta: el cliente nunca lo lee.
        ->and($cadena['marcar'])->toBeTrue();
});

it('encadena igual si las compañías contestaron dentro del mismo turno', function () {
    $conversation = Conversation::factory()->create();
    Quote::factory()->create(['conversation_id' => $conversation->id, 'status' => 'processed']);

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(),
        flujoEnEstado(['vehicle_identified' => true]),
    );

    expect($cadena)->not->toBeNull();
});

/** Ramas NeedsFact y NotQuotable: no crean quote, y el turno cierra preguntando algo. */
it('no encadena si la identificación no dejó ninguna cotización', function () {
    $conversation = Conversation::factory()->create();

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(),
        flujoEnEstado(['vehicle_identified' => true]),
    );

    expect($cadena)->toBeNull();
});

it('no encadena por una cotización de otro día', function () {
    $conversation = Conversation::factory()->create();
    Quote::factory()->vencida()->create([
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(),
        flujoEnEstado(['vehicle_identified' => true]),
    );

    expect($cadena)->toBeNull();
});

it('no encadena si la cobertura quedó registrada en el mismo turno', function () {
    $conversation = Conversation::factory()->create();
    Quote::factory()->create(['conversation_id' => $conversation->id, 'status' => 'pending']);

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(),
        flujoEnEstado(['vehicle_identified' => true, 'coverage_set' => true]),
    );

    expect($cadena)->toBeNull();
});

it('no encadena cuando el turno no movió el estado', function () {
    $conversation = Conversation::factory()->create();
    Quote::factory()->create(['conversation_id' => $conversation->id, 'status' => 'pending']);

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(['vehicle_identified' => true]),
        flujoEnEstado(['vehicle_identified' => true]),
    );

    expect($cadena)->toBeNull();
});

/** La cadena que ya existía: QuoteAgent presenta y sigue el cierre, sin marcar lo descartado. */
it('sigue encadenando al cierre cuando la cotización se presenta', function () {
    $conversation = Conversation::factory()->create();

    $cadena = cadenaDecididaPara(
        $conversation,
        flujoEnEstado(['vehicle_identified' => true, 'coverage_set' => true]),
        flujoEnEstado(['vehicle_identified' => true, 'coverage_set' => true, 'quote_ready' => true]),
    );

    expect($cadena)->not->toBeNull()
        ->and($cadena['disparador'])->toBe('Cotizaciones listas')
        ->and($cadena['marcar'])->toBeFalse();
});

it('marca la respuesta descartada sin borrar sus tool calls', function () {
    $conversation = Conversation::factory()->create();

    foreach (['primera', 'segunda'] as $texto) {
        DB::table('agent_conversation_messages')->insert([
            // uuid7, igual que `DatabaseConversationStore`: la marca busca la última fila con
            // `orderByDesc('id')`, que ordena por tiempo sólo si los ids son ordenables.
            'id' => (string) Str::uuid7(),
            'conversation_id' => (string) $conversation->id,
            'user_id' => $conversation->id,
            'agent' => 'App\AI\Agents\VehicleIdentifierAgent',
            'role' => 'assistant',
            'content' => "respuesta {$texto}",
            'attachments' => json_encode([]),
            'tool_calls' => json_encode([['id' => 'call_1', 'name' => 'identify_vehicle', 'arguments' => []]]),
            'tool_results' => json_encode([]),
            'usage' => json_encode([]),
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    MemoriaDelAgente::marcarNoEntregada($conversation, '[NO ENTREGADO]');

    $filas = DB::table('agent_conversation_messages')
        ->where('user_id', $conversation->id)
        ->get()
        ->keyBy(fn (object $fila): string => str_contains((string) $fila->content, 'primera') ? 'primera' : 'segunda');

    expect($filas['segunda']->content)->toStartWith('[NO ENTREGADO]')
        ->and($filas['segunda']->content)->toContain('respuesta segunda')
        ->and($filas['segunda']->tool_calls)->toContain('identify_vehicle')
        ->and($filas['primera']->content)->not->toContain('[NO ENTREGADO]');
});
