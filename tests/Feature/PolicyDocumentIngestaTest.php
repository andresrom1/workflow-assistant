<?php

use App\Enums\IngestaStatus;
use App\Jobs\ExtractIngestedDocument;
use App\Models\IngestedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Contrato v2 mínimo válido: el cliente ya NO extrae campos (parser regex v5 retirado) —
 * solo manda el texto plano (pdfplumber) + los datos del archivo. La clasificación y
 * extracción corren server-side vía {@see ExtractIngestedDocument} (ver
 * tests/Feature/ExtractIngestedDocumentTest.php para ese pipeline).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validIngestaContractV2(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 2,
        'archivo' => [
            'nombre_original' => 'Caratula Anual (5).pdf',
            'hash_sha256' => str_repeat('a', 64),
            'detectado_en' => '2026-06-24T08:00:00-03:00',
        ],
        'texto' => 'SANCOR SEGUROS - Póliza N° 000031184413 - Asegurado: SICOT LEONARDO FABIO...',
    ], $overrides);
}

/**
 * POST multipart con `metadata` (JSON string) + `file` (PDF), pidiendo respuesta JSON.
 *
 * @param  array<string, mixed>  $contract
 */
function postIngestaV2(array $contract, ?UploadedFile $file = null): TestResponse
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
    postIngestaV2(validIngestaContractV2())->assertUnauthorized();
});

it('acepta un contrato v2 válido, estaciona la fila en_extraccion, sube el PDF a R2 y despacha la extracción', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $hash = str_repeat('a', 64);

    postIngestaV2(validIngestaContractV2())
        ->assertCreated()
        ->assertJson(['status' => 'staged', 'pendiente' => true]);

    $this->assertDatabaseHas('ingested_documents', [
        'hash_sha256' => $hash,
        'status' => 'en_extraccion',
    ]);

    Storage::disk('r2')->assertExists("ingesta/{$hash}.pdf");

    Queue::assertPushed(ExtractIngestedDocument::class, fn (ExtractIngestedDocument $job): bool => $job->document->hash_sha256 === $hash);
});

it('rechaza con 422 si falta el archivo', function (): void {
    Sanctum::actingAs(User::factory()->create());

    test()->post('/api/ingesta/documentos', [
        'metadata' => json_encode(validIngestaContractV2()),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('file');
});

it('rechaza con 422 si el archivo no es PDF', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postIngestaV2(validIngestaContractV2(), UploadedFile::fake()->create('foto.png', 100, 'image/png'))
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

    $contract = validIngestaContractV2();
    $contract['archivo']['hash_sha256'] = null;

    postIngestaV2($contract)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('metadata');
});

it('rechaza con 422 si el schema_version no es 2 (v1 retirado)', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postIngestaV2(validIngestaContractV2(['schema_version' => 1]))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('metadata');
});

it('degrada en vez de rechazar cuando texto viene con un tipo inesperado', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $contract = validIngestaContractV2(['texto' => ['no' => 'es un string']]);
    $contract['archivo']['hash_sha256'] = str_repeat('b', 64);

    // No se rechaza: se estaciona igual, el job de extracción degradará por falta de texto.
    postIngestaV2($contract)->assertCreated();

    $this->assertDatabaseHas('ingested_documents', [
        'hash_sha256' => str_repeat('b', 64),
        'status' => 'en_extraccion',
    ]);
});

it('acepta texto null (PDF sin texto extraíble) y lo estaciona igual', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $contract = validIngestaContractV2(['texto' => null]);
    $contract['archivo']['hash_sha256'] = str_repeat('c', 64);

    postIngestaV2($contract)->assertCreated();

    $doc = IngestedDocument::where('hash_sha256', str_repeat('c', 64))->firstOrFail();
    expect(data_get($doc->payload, 'texto'))->toBeNull();
});

it('es idempotente: el mismo hash no duplica filas ni re-despacha la extracción', function (): void {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    postIngestaV2(validIngestaContractV2())->assertCreated();

    // Reenvío del mismo PDF (mismo hash): 200 idempotente, sin segunda fila.
    postIngestaV2(validIngestaContractV2())
        ->assertOk()
        ->assertJson(['status' => 'duplicate']);

    expect(IngestedDocument::where('hash_sha256', str_repeat('a', 64))->count())->toBe(1);
    Queue::assertPushed(ExtractIngestedDocument::class, 1);
});

it('un duplicado ya descartado_auto responde pendiente=false', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $doc = IngestedDocument::factory()->create([
        'hash_sha256' => str_repeat('d', 64),
        'status' => IngestaStatus::DescartadoAuto,
    ]);

    postIngestaV2(validIngestaContractV2(['archivo' => [
        'nombre_original' => 'x.pdf', 'hash_sha256' => str_repeat('d', 64), 'detectado_en' => null,
    ]]))
        ->assertOk()
        ->assertJson(['status' => 'duplicate', 'pendiente' => false, 'ingested_document_id' => $doc->id]);
});
