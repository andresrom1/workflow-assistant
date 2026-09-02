<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `PresentQuoteOptionsTool` deja los botones y el link en `metadata` y los consume el final del
 * turno. Si el turno muere en el medio —el 2026-09-02 lo mató el alarm del job, dos segundos
 * después de que la tool escribiera— esos pendientes quedan huérfanos esperando para pegarse al
 * próximo mensaje que salga, sea cual sea. Ver ROADMAP.
 *
 * `pullPending()` es privado; se invoca por reflexión (patrón aceptado en CLAUDE.md).
 *
 * @return array{buttons: list<array{id: string, title: string}>|null, public_link: string|null}
 */
function pendientesDe(Conversation $conversation): array
{
    $orchestrator = new InsuranceOrchestrator(Mockery::mock(WhatsAppAdapter::class));
    $metodo = (new ReflectionClass($orchestrator))->getMethod('pullPending');
    $metodo->setAccessible(true);

    return $metodo->invoke($orchestrator, $conversation);
}

/** @param array<string, mixed> $extra */
function conversacionConPendientes(array $extra): Conversation
{
    return Conversation::factory()->create([
        'metadata' => array_merge([
            'pending_interactive' => ['buttons' => [['id' => 'alt:1', 'title' => 'Sancor $54.2K']]],
            'pending_public_link' => 'https://mangobroker.com.ar/cotizaciones/abc123',
        ], $extra),
    ]);
}

it('entrega los pendientes frescos y los limpia', function () {
    $conversation = conversacionConPendientes(['pending_at' => now()->toIso8601String()]);

    $pendientes = pendientesDe($conversation);

    expect($pendientes['buttons'])->toHaveCount(1)
        ->and($pendientes['public_link'])->toContain('abc123')
        ->and($conversation->fresh()->metadata)->not->toHaveKey('pending_interactive');
});

it('descarta los pendientes de un turno que murió hace rato', function () {
    $conversation = conversacionConPendientes(['pending_at' => now()->subHour()->toIso8601String()]);

    $pendientes = pendientesDe($conversation);

    expect($pendientes['buttons'])->toBeNull()
        ->and($pendientes['public_link'])->toBeNull();
});

/** Los que quedaron colgados antes de que existiera el sello no tienen forma de fecharse. */
it('descarta los pendientes sin sello', function () {
    $conversation = conversacionConPendientes([]);

    $pendientes = pendientesDe($conversation);

    expect($pendientes['buttons'])->toBeNull()
        ->and($pendientes['public_link'])->toBeNull();
});

it('limpia la metadata aunque descarte los pendientes', function () {
    $conversation = conversacionConPendientes(['pending_at' => now()->subHour()->toIso8601String()]);

    pendientesDe($conversation);

    expect($conversation->fresh()->metadata)->not->toHaveKey('pending_interactive')
        ->and($conversation->fresh()->metadata)->not->toHaveKey('pending_public_link')
        ->and($conversation->fresh()->metadata)->not->toHaveKey('pending_at');
});

it('no toca la metadata cuando no hay nada pendiente', function () {
    $conversation = Conversation::factory()->create(['metadata' => ['ai_state' => ['coverage_set' => true]]]);

    $pendientes = pendientesDe($conversation);

    expect($pendientes)->toBe(['buttons' => null, 'public_link' => null])
        ->and($conversation->fresh()->metadata)->toHaveKey('ai_state');
});
