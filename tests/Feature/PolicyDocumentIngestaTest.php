<?php

use App\Models\IngestedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Contrato §2 mínimo válido. `$overrides` reemplaza secciones top-level completas
 * (documento, tomador, riesgo, fechas, archivo, extraccion).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validIngestaContract(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 1,
        'documento' => [
            'kind' => 'poliza',
            'compania' => 'Sancor Seguros',
            'numero_poliza' => '000031184413',
            'endoso_numero' => null,
        ],
        'tomador' => [
            'tipo_persona' => 'fisica',
            'first_name' => 'SICOT LEONARDO',
            'last_name' => 'FABIO',
            'razon_social' => null,
            'documento_tipo' => 'DNI',
            'documento_numero' => '21407965',
        ],
        'riesgo' => [
            'tipo' => 'vehicle',
            'patente' => 'AB235OR',
            'marca' => null,
            'modelo' => null,
            'version' => null,
            'year' => '2017',
            'combustible' => null,
            'uso' => null,
            'codigo_postal' => null,
        ],
        'fechas' => [
            'emision' => null,
            'vigencia_desde' => '2026-02-19',
            'vigencia_hasta' => '2027-02-19',
        ],
        'archivo' => [
            'nombre_original' => 'Caratula Anual (5).pdf',
            'hash_sha256' => str_repeat('a', 64),
            'detectado_en' => '2026-06-24T08:00:00-03:00',
        ],
        'extraccion' => [
            'parser' => 'policy_parser_v5',
            'campos_no_extraidos' => ['marca', 'modelo', 'emision'],
        ],
    ], $overrides);
}

/**
 * POST multipart con `metadata` (JSON string) + `file` (PDF), pidiendo respuesta JSON.
 *
 * @param  array<string, mixed>  $contract
 */
function postIngesta(array $contract, ?UploadedFile $file = null): TestResponse
{
    $file ??= UploadedFile::fake()->create('poliza.pdf', 200, 'application/pdf');

    return test()->post('/api/ingesta/documentos', [
        'metadata' => json_encode($contract),
        'file' => $file,
    ], ['Accept' => 'application/json']);
}

beforeEach(function (): void {
    Storage::fake('r2');
});

it('rechaza sin token (401)', function (): void {
    postIngesta(validIngestaContract())->assertUnauthorized();
});

it('acepta un contrato válido, estaciona la fila y sube el PDF a R2', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $hash = str_repeat('a', 64);

    postIngesta(validIngestaContract())
        ->assertCreated()
        ->assertJson(['status' => 'staged', 'pendiente' => true]);

    $this->assertDatabaseHas('ingested_documents', [
        'hash_sha256' => $hash,
        'kind' => 'poliza',
        'compania' => 'Sancor Seguros',
        'numero_poliza' => '000031184413',
        'documento_numero' => '21407965',
        'patente' => 'AB235OR',
        'status' => 'pendiente',
    ]);

    Storage::disk('r2')->assertExists("ingesta/{$hash}.pdf");
});

it('rechaza con 422 si falta el archivo', function (): void {
    Sanctum::actingAs(User::factory()->create());

    test()->post('/api/ingesta/documentos', [
        'metadata' => json_encode(validIngestaContract()),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('file');
});

it('rechaza con 422 si el archivo no es PDF', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postIngesta(validIngestaContract(), UploadedFile::fake()->create('foto.png', 100, 'image/png'))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('file');
});

it('rechaza con 422 si metadata no es JSON válido', function (): void {
    Sanctum::actingAs(User::factory()->create());

    test()->post('/api/ingesta/documentos', [
        'metadata' => 'no-soy-json',
        'file' => UploadedFile::fake()->create('poliza.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('metadata');
});

it('rechaza con 422 si falta el hash (sin él no hay idempotencia)', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $contract = validIngestaContract();
    $contract['archivo']['hash_sha256'] = null;

    postIngesta($contract)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('metadata');
});

it('rechaza con 422 si el schema_version no es soportado', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postIngesta(validIngestaContract(['schema_version' => 2]))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('metadata');
});

it('degrada en vez de rechazar cuando faltan claves mínimas (caso Mercantil sin número ni tomador)', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $contract = validIngestaContract([
        'documento' => ['kind' => 'circulation-card', 'compania' => 'Mercantil Andina', 'numero_poliza' => null, 'endoso_numero' => null],
        'tomador' => ['tipo_persona' => null, 'first_name' => null, 'last_name' => null, 'razon_social' => null, 'documento_tipo' => null, 'documento_numero' => null],
    ]);
    $contract['archivo']['hash_sha256'] = str_repeat('b', 64);

    // El contrato incompleto NO se rechaza: se estaciona en Pendientes para confirmación.
    postIngesta($contract)->assertCreated();

    $this->assertDatabaseHas('ingested_documents', [
        'hash_sha256' => str_repeat('b', 64),
        'kind' => 'circulation-card',
        'numero_poliza' => null,
        'documento_numero' => null,
        'patente' => 'AB235OR',
        'status' => 'pendiente',
    ]);
});

it('coerce un kind desconocido a Otro antes de estacionar', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $contract = validIngestaContract([
        'documento' => ['kind' => 'inventado', 'compania' => 'Sancor Seguros', 'numero_poliza' => '123', 'endoso_numero' => null],
    ]);
    $contract['archivo']['hash_sha256'] = str_repeat('c', 64);

    postIngesta($contract)->assertCreated();

    $this->assertDatabaseHas('ingested_documents', [
        'hash_sha256' => str_repeat('c', 64),
        'kind' => 'otro',
    ]);
});

it('es idempotente: el mismo hash no duplica filas y responde 200', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postIngesta(validIngestaContract())->assertCreated();

    // Reenvío del mismo PDF (mismo hash): 200 idempotente, sin segunda fila.
    postIngesta(validIngestaContract())
        ->assertOk()
        ->assertJson(['status' => 'duplicate']);

    expect(IngestedDocument::where('hash_sha256', str_repeat('a', 64))->count())->toBe(1);
});
