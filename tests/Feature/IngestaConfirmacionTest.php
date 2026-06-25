<?php

use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\IngestedDocument;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use App\Services\IngestaConfirmacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Crea un IngestedDocument pendiente con columnas y payload consistentes.
 *
 * @param  array<string, mixed>  $attrs
 */
function stagedDoc(array $attrs = []): IngestedDocument
{
    $defaults = [
        'kind' => PolicyDocumentKind::Poliza,
        'compania' => 'Sancor Seguros',
        'numero_poliza' => '000031184413',
        'documento_numero' => '21407965',
        'patente' => 'AB235OR',
        'status' => IngestaStatus::Pendiente,
        'poliza_id' => null,
        'policy_document_id' => null,
    ];
    $merged = array_merge($defaults, $attrs);

    $merged['payload'] = array_merge([
        'tomador' => ['first_name' => 'SICOT LEONARDO', 'last_name' => 'FABIO', 'razon_social' => null],
        'riesgo' => ['tipo' => 'vehicle', 'patente' => $merged['patente'], 'marca' => 'TOYOTA', 'year' => '2017'],
        'fechas' => ['emision' => null, 'vigencia_desde' => '2026-02-19', 'vigencia_hasta' => now()->addYear()->toDateString()],
    ], $attrs['payload'] ?? []);

    return IngestedDocument::factory()->create($merged);
}

function confirmacion(): IngestaConfirmacionService
{
    return app(IngestaConfirmacionService::class);
}

beforeEach(function (): void {
    Storage::fake('r2');
    $this->user = User::factory()->create();
});

it('materializa la cadena Customer→Risk→Poliza→PolicyDocument al confirmar', function (): void {
    $doc = stagedDoc();

    $result = confirmacion()->confirm($doc);

    $customer = Customer::where('dni', '21407965')->first();
    expect($customer)->not->toBeNull();

    $risk = Risk::where('customer_id', $customer->id)->first();
    expect($risk->metadata['patente'])->toBe('AB235OR');

    $poliza = Poliza::where('numero', '000031184413')->where('company', 'Sancor Seguros')->first();
    expect($poliza)->not->toBeNull()
        ->and($poliza->risk_id)->toBe($risk->id)
        ->and($poliza->metadata['origen'])->toBe('ingesta_local')
        ->and($poliza->estado)->toBe(PolizaEstado::Vigente);

    $pd = PolicyDocument::where('poliza_id', $poliza->id)->first();
    expect($pd->source)->toBe(PolicyDocumentSource::LocalIngesta);

    expect($result->status)->toBe(IngestaStatus::Confirmado)
        ->and($result->poliza_id)->toBe($poliza->id)
        ->and($result->policy_document_id)->toBe($pd->id);
});

it('acumula un segundo documento del mismo contrato sobre la misma póliza (find-or-create)', function (): void {
    $frente = stagedDoc(['kind' => PolicyDocumentKind::Poliza]);
    confirmacion()->confirm($frente);

    $tarjeta = stagedDoc([
        'kind' => PolicyDocumentKind::CirculationCard,
        'numero_poliza' => '000031184413',
        'hash_sha256' => str_repeat('f', 64),
    ]);
    confirmacion()->confirm($tarjeta);

    expect(Poliza::where('numero', '000031184413')->count())->toBe(1);
    expect(PolicyDocument::count())->toBe(2);
});

it('reutiliza un cliente existente por DNI en vez de duplicarlo', function (): void {
    $existing = Customer::factory()->create(['dni' => '21407965']);

    confirmacion()->confirm(stagedDoc());

    expect(Customer::where('dni', '21407965')->count())->toBe(1);
    expect(Poliza::first()->risk->customer_id)->toBe($existing->id);
});

it('adjunta un documento sin número a la póliza existente del mismo Risk (fallback por patente)', function (): void {
    confirmacion()->confirm(stagedDoc());

    $tarjeta = stagedDoc([
        'kind' => PolicyDocumentKind::CirculationCard,
        'numero_poliza' => null,
        'documento_numero' => null,
        'hash_sha256' => str_repeat('e', 64),
    ]);

    confirmacion()->confirm($tarjeta);

    expect(Poliza::count())->toBe(1);
    expect(PolicyDocument::count())->toBe(2);
});

it('rechaza un documento sin número cuando no hay póliza por patente', function (): void {
    $tarjeta = stagedDoc([
        'numero_poliza' => null,
        'documento_numero' => null,
        'patente' => 'ZZ999ZZ',
    ]);

    expect(fn () => confirmacion()->confirm($tarjeta))
        ->toThrow(ValidationException::class);

    expect(Poliza::count())->toBe(0);
});

it('infiere estado vencida cuando la vigencia ya pasó', function (): void {
    $doc = stagedDoc(['payload' => ['fechas' => ['vigencia_hasta' => now()->subMonth()->toDateString()]]]);

    confirmacion()->confirm($doc);

    expect(Poliza::first()->estado)->toBe(PolizaEstado::Vencida);
});

it('vincula la renovación cuando se confirma el contrato_anterior_id', function (): void {
    confirmacion()->confirm(stagedDoc());
    $anterior = Poliza::first();

    $renovacion = stagedDoc([
        'numero_poliza' => '000099999999',
        'hash_sha256' => str_repeat('d', 64),
    ]);

    confirmacion()->confirm($renovacion, ['contrato_anterior_id' => $anterior->id]);

    $nueva = Poliza::where('numero', '000099999999')->first();
    expect($nueva->contrato_anterior_id)->toBe($anterior->id);
});

it('descarta sin materializar nada', function (): void {
    $doc = stagedDoc();

    confirmacion()->discard($doc);

    expect($doc->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and(Poliza::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
});

it('no permite confirmar dos veces el mismo documento', function (): void {
    $doc = stagedDoc();
    confirmacion()->confirm($doc);

    expect(fn () => confirmacion()->confirm($doc->refresh()))
        ->toThrow(ValidationException::class);
});

// ─── Controller ───────────────────────────────────────────────────────────────

it('el index agrupa los pendientes por contrato', function (): void {
    stagedDoc();
    stagedDoc(['kind' => PolicyDocumentKind::CirculationCard, 'hash_sha256' => str_repeat('1', 64)]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/PendientesIngesta')
            ->has('grupos', 1)
            ->has('grupos.0.documentos', 2));
});

it('confirmar desde el controller materializa la póliza', function (): void {
    $doc = stagedDoc();

    $this->actingAs($this->user)
        ->post(route('ingesta-pendientes.confirm', $doc), [])
        ->assertRedirect();

    expect(Poliza::where('numero', '000031184413')->exists())->toBeTrue()
        ->and($doc->refresh()->status)->toBe(IngestaStatus::Confirmado);
});

it('descartar desde el controller no crea nada', function (): void {
    $doc = stagedDoc();

    $this->actingAs($this->user)
        ->delete(route('ingesta-pendientes.discard', $doc))
        ->assertRedirect();

    expect($doc->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and(Poliza::count())->toBe(0);
});
