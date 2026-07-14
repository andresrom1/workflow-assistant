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

it('reconoce al mismo cliente cargado por DNI y luego por CUIL (misma clave)', function (): void {
    $existing = Customer::factory()->create([
        'dni' => '21407965', 'document_type_id' => 'dni', 'person_type_id' => 'fisica',
    ]);

    // Ingesta con el CUIL de la misma persona: 20-21407965-4 → clave = DNI 21407965
    $doc = stagedDoc([
        'documento_numero' => '20214079654',
        'payload' => ['tomador' => ['documento_tipo' => 'CUIL', 'tipo_persona' => 'fisica']],
    ]);
    confirmacion()->confirm($doc);

    expect(Customer::count())->toBe(1)
        ->and(Poliza::first()->risk->customer_id)->toBe($existing->id);
});

it('crea un cliente jurídico con clave = CUIT completo', function (): void {
    $doc = stagedDoc([
        'documento_numero' => '30717843181',
        'payload' => ['tomador' => ['razon_social' => 'LJM INGENIERIA SAS', 'documento_tipo' => 'CUIT', 'tipo_persona' => 'juridica']],
    ]);
    confirmacion()->confirm($doc);

    $customer = Customer::where('documento_key', '30717843181')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->person_type_id)->toBe('juridica');
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

it('marca vigente el mismo día del vencimiento (no vencida)', function (): void {
    $doc = stagedDoc(['payload' => ['fechas' => [
        'vigencia_desde' => now()->subMonths(6)->toDateString(),
        'vigencia_hasta' => now()->toDateString(),
    ]]]);

    confirmacion()->confirm($doc);

    expect(Poliza::first()->estado)->toBe(PolizaEstado::Vigente);
});

it('marca emitida cuando la vigencia aún no arrancó', function (): void {
    $doc = stagedDoc(['payload' => ['fechas' => [
        'vigencia_desde' => now()->addWeek()->toDateString(),
        'vigencia_hasta' => now()->addYear()->toDateString(),
    ]]]);

    confirmacion()->confirm($doc);

    expect(Poliza::first()->estado)->toBe(PolizaEstado::Emitida);
});

it('marca emitida cuando falta la fecha de fin', function (): void {
    $doc = stagedDoc(['payload' => ['fechas' => [
        'vigencia_desde' => now()->subMonth()->toDateString(),
        'vigencia_hasta' => null,
    ]]]);

    confirmacion()->confirm($doc);

    expect(Poliza::first()->estado)->toBe(PolizaEstado::Emitida);
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

it('el index agrupa los pendientes por contrato y expone key/prefill/resumen', function (): void {
    stagedDoc();
    stagedDoc(['kind' => PolicyDocumentKind::CirculationCard, 'hash_sha256' => str_repeat('1', 64)]);

    $this->actingAs($this->user)
        ->get(route('ingesta-pendientes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/PendientesIngesta')
            ->has('grupos', 1)
            ->has('grupos.0.documentos', 2)
            // Clave v2: compañía + número normalizado (ver fix de agrupación, doc v3/04).
            ->where('grupos.0.key', 'num:sancor seguros:000031184413')
            ->where('grupos.0.resumen.tomador', 'SICOT LEONARDO FABIO')
            ->where('grupos.0.prefill.documento_numero', '21407965')
            ->has('grupos.0.faltantes_count'));
});

it('confirmar desde el controller materializa la póliza', function (): void {
    $doc = stagedDoc();

    $this->actingAs($this->user)
        ->post(route('ingesta-pendientes.confirm', $doc), [])
        ->assertRedirect();

    expect(Poliza::where('numero', '000031184413')->exists())->toBeTrue()
        ->and($doc->refresh()->status)->toBe(IngestaStatus::Confirmado);
});

it('confirmar contrato materializa una póliza y adjunta todos los docs', function (): void {
    $poliza = stagedDoc(['kind' => PolicyDocumentKind::Poliza]);
    $cert = stagedDoc(['kind' => PolicyDocumentKind::Certificado, 'hash_sha256' => str_repeat('a', 64)]);
    $tarjeta = stagedDoc(['kind' => PolicyDocumentKind::CirculationCard, 'hash_sha256' => str_repeat('b', 64)]);

    $this->actingAs($this->user)
        ->post(route('ingesta-pendientes.confirmar-contrato'), [
            'ids' => [$poliza->id, $cert->id, $tarjeta->id],
        ])
        ->assertRedirect();

    expect(Poliza::where('numero', '000031184413')->count())->toBe(1)
        ->and(PolicyDocument::count())->toBe(3);

    foreach ([$poliza, $cert, $tarjeta] as $doc) {
        expect($doc->refresh()->status)->toBe(IngestaStatus::Confirmado)
            ->and($doc->poliza_id)->not->toBeNull();
    }
});

it('descartar desde el controller no crea nada', function (): void {
    $doc = stagedDoc();

    $this->actingAs($this->user)
        ->delete(route('ingesta-pendientes.discard', $doc))
        ->assertRedirect();

    expect($doc->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and(Poliza::count())->toBe(0);
});

it('el lookup avisa cliente existente por la clave de identidad (CUIL → DNI)', function (): void {
    Customer::factory()->create([
        'dni' => '21407965', 'document_type_id' => 'dni', 'person_type_id' => 'fisica',
        'first_name' => 'Juan', 'last_name' => 'Pérez',
    ]);

    $this->actingAs($this->user)
        ->getJson(route('ingesta-pendientes.buscar-cliente', [
            'documento' => '20-21407965-4', 'document_type' => 'cuil', 'person_type' => 'fisica',
        ]))
        ->assertOk()
        ->assertJson(['existe' => true, 'cliente' => ['first_name' => 'Juan', 'last_name' => 'Pérez']]);
});

it('el lookup deriva nombre/apellido de name cuando el cliente no los tiene', function (): void {
    Customer::factory()->create([
        'dni' => '29034000', 'document_type_id' => 'dni', 'person_type_id' => 'fisica',
        'name' => 'Luis Alberto Ochoa', 'first_name' => null, 'last_name' => null,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('ingesta-pendientes.buscar-cliente', [
            'documento' => '29034000', 'document_type' => 'dni', 'person_type' => 'fisica',
        ]))
        ->assertOk()
        ->assertJson(['existe' => true, 'cliente' => ['first_name' => 'Luis', 'last_name' => 'Alberto Ochoa']]);
});

it('el lookup avisa cliente nuevo cuando no existe', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ingesta-pendientes.buscar-cliente', [
            'documento' => '99999999', 'document_type' => 'dni', 'person_type' => 'fisica',
        ]))
        ->assertOk()
        ->assertJson(['existe' => false, 'cliente' => null]);
});

it('descartar contrato descarta todos sus docs', function (): void {
    $a = stagedDoc();
    $b = stagedDoc(['kind' => PolicyDocumentKind::CirculationCard, 'hash_sha256' => str_repeat('c', 64)]);

    $this->actingAs($this->user)
        ->post(route('ingesta-pendientes.descartar-contrato'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect($a->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and($b->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and(Poliza::count())->toBe(0);
});
