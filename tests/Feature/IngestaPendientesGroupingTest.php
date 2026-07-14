<?php

use App\Enums\PolicyDocumentKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Cubre el fix de agrupación de v2 (docs/v3/04-ingesta-local-documentos.md): la clave pasa
 * de `num:{numero}` a `num:{compania}:{numero_normalizado}` para no colisionar entre
 * compañías y no partirse por diferencias de formato del número que el LLM puede
 * introducir (ver hallazgo del smoke test: Triunfo "1.912.367" vs "458 1.912.367").
 * Reusa `stagedDoc()` de tests/Feature/IngestaConfirmacionTest.php (mismos defaults).
 */
beforeEach(function (): void {
    Storage::fake('r2');
    $this->user = User::factory()->create();
});

it('agrupa dos filas con el mismo numero en distinto formato de separadores (mismos dígitos)', function (): void {
    stagedDoc([
        'compania' => 'Triunfo Cooperativa de Seguros',
        'numero_poliza' => '1.912.367',
        'patente' => 'VDO674',
        'hash_sha256' => str_repeat('2', 64),
    ]);
    stagedDoc([
        'compania' => 'Triunfo Cooperativa de Seguros',
        'numero_poliza' => '1912367', // mismos dígitos, sin puntos
        'patente' => 'VDO674',
        'hash_sha256' => str_repeat('3', 64),
    ]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('grupos', 1)->has('grupos.0.documentos', 2));
});

it('LIMITACIÓN ACEPTADA: si el LLM deja un prefijo de organizador, NO se fusiona solo (queda en 2 grupos)', function (): void {
    // El prompt le pide al LLM devolver el número SIN prefijo de organizador (ver
    // IngestaExtractorAgent), pero si igual lo deja, el server no puede distinguir "458"
    // de un dígito real del número — normalizar a solo-dígitos no alcanza acá. Por diseño
    // (doc v3/04): el humano confirma cada documento por separado; resolvePoliza() los une
    // al confirmar por company+numero o por patente. No se sobre-ingenieriza este caso.
    stagedDoc([
        'compania' => 'Triunfo Cooperativa de Seguros',
        'numero_poliza' => '1912367',
        'patente' => 'VDO674',
        'hash_sha256' => str_repeat('8', 64),
    ]);
    stagedDoc([
        'compania' => 'Triunfo Cooperativa de Seguros',
        'numero_poliza' => '458 1912367', // prefijo de organizador que el LLM no limpió
        'patente' => 'VDO674',
        'kind' => PolicyDocumentKind::CirculationCard,
        'hash_sha256' => str_repeat('9', 64),
    ]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('grupos', 2));
});

it('fusiona un documento sin numero (misma patente) al contrato ya identificado por otro documento', function (): void {
    stagedDoc([
        'compania' => 'Sancor Seguros',
        'numero_poliza' => '000031184413',
        'patente' => 'AB235OR',
        'hash_sha256' => str_repeat('4', 64),
    ]);
    stagedDoc([
        'compania' => 'Sancor Seguros',
        'numero_poliza' => null,
        'patente' => 'AB235OR',
        'kind' => PolicyDocumentKind::Cupon,
        'hash_sha256' => str_repeat('5', 64),
    ]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('grupos', 1)->has('grupos.0.documentos', 2));
});

it('mismo numero, distinta compania: quedan en grupos separados (no colisionan)', function (): void {
    stagedDoc([
        'compania' => 'Sancor Seguros',
        'numero_poliza' => '123456',
        'patente' => 'AAA111',
        'hash_sha256' => str_repeat('6', 64),
    ]);
    stagedDoc([
        'compania' => 'Río Uruguay',
        'numero_poliza' => '123456',
        'patente' => 'BBB222',
        'hash_sha256' => str_repeat('7', 64),
    ]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('grupos', 2));
});
