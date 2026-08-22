<?php

use App\AI\Agents\IngestaExtractorAgent;
use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Jobs\ExtractIngestedDocument;
use App\Models\IngestedDocument;
use App\Models\User;
use App\Services\IngestaConfirmacionService;
use App\Services\IngestaDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Respuestas REALES de deepseek-chat, capturadas en el smoke test contra el corpus
 * (docs/v3/04-ingesta-local-documentos.md — sección "Ingesta v2"). No son inventadas: son
 * lo que el modelo devolvió de verdad al procesar `Sancor/Caratula Anual (5).pdf` y una
 * cotización real de San Cristóbal.
 */
function fixtureSancorPoliza(): string
{
    return json_encode([
        'clase' => 'poliza',
        'compania' => 'Sancor Cooperativa de Seguros Ltda.',
        'numero_poliza' => '000031184413',
        'endoso_numero' => null,
        'tomador' => [
            'tipo_persona' => 'fisica',
            'first_name' => 'LEONARDO FABIO',
            'last_name' => 'SICOT',
            'razon_social' => null,
            'documento_tipo' => 'DNI',
            'documento_numero' => '21407965',
        ],
        'riesgo' => [
            'patente' => 'AB235OR', 'marca' => null, 'modelo' => null,
            'year' => '2017', 'combustible' => null, 'uso' => null,
        ],
        'fechas' => [
            'emision' => null, 'vigencia_desde' => '2026-02-19', 'vigencia_hasta' => '2027-02-19',
        ],
    ]);
}

function fixtureCotizacionBasura(): string
{
    return json_encode([
        'clase' => 'cotizacion',
        'compania' => 'San Cristóbal',
        'numero_poliza' => null,
        'endoso_numero' => null,
        'tomador' => [
            'tipo_persona' => 'fisica', 'first_name' => 'PAULA', 'last_name' => 'ETKIN',
            'razon_social' => null, 'documento_tipo' => 'CUIT', 'documento_numero' => '27349092811',
        ],
        'riesgo' => ['patente' => null, 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null],
        'fechas' => ['emision' => null, 'vigencia_desde' => '2026-01-28', 'vigencia_hasta' => '2027-01-28'],
    ]);
}

/**
 * Estaciona una fila lista para extraer (status en_extraccion + texto en el payload).
 *
 * @param  array<string, mixed>  $overrides
 */
function extractionStagedDoc(array $overrides = []): IngestedDocument
{
    return IngestedDocument::factory()->create(array_replace([
        'status' => IngestaStatus::EnExtraccion,
        'payload' => [
            'schema_version' => 2,
            'archivo' => ['nombre_original' => 'test.pdf', 'hash_sha256' => str_repeat('a', 64), 'detectado_en' => null],
            'texto' => 'SANCOR SEGUROS - Póliza N° 000031184413 - Asegurado: SICOT LEONARDO FABIO - Dominio: AB235OR',
        ],
    ], $overrides));
}

/**
 * Corre el job directo (bypasseando el dispatch a la conexión `database_long`, que en
 * tests no ejecuta sincrónicamente) — simula lo que hace el worker real.
 */
function runIngestaJob(IngestedDocument $doc): IngestedDocument
{
    (new ExtractIngestedDocument($doc))->handle(
        app(IngestaExtractorAgent::class),
        app(IngestaDocumentoService::class),
    );

    return $doc->fresh();
}

it('extrae un documento del corpus y lo deja pendiente con el payload shape v1', function (): void {
    IngestaExtractorAgent::fake([fixtureSancorPoliza()]);

    $doc = runIngestaJob(extractionStagedDoc());

    expect($doc->status)->toBe(IngestaStatus::Pendiente)
        ->and($doc->kind)->toBe(PolicyDocumentKind::Poliza)
        ->and($doc->compania)->toBe('Sancor Seguros') // normalizado por alias, no "Sancor Cooperativa..."
        ->and($doc->numero_poliza)->toBe('000031184413')
        ->and($doc->documento_numero)->toBe('21407965')
        ->and($doc->patente)->toBe('AB235OR');

    // El shape del payload post-extracción es el contrato v1 (documento/tomador/riesgo/fechas):
    // esto es lo que hace que IngestaConfirmacionService siga funcionando sin cambios.
    expect(data_get($doc->payload, 'documento.kind'))->toBe('poliza')
        ->and(data_get($doc->payload, 'tomador.first_name'))->toBe('LEONARDO FABIO')
        ->and(data_get($doc->payload, 'tomador.last_name'))->toBe('SICOT')
        ->and(data_get($doc->payload, 'fechas.vigencia_hasta'))->toBe('2027-02-19')
        ->and(data_get($doc->payload, 'extraccion.parser'))->toBe('deepseek-v2');
});

it('descarta solo un documento clasificado como no-póliza (descartado_auto)', function (): void {
    IngestaExtractorAgent::fake([fixtureCotizacionBasura()]);

    $doc = runIngestaJob(extractionStagedDoc());

    expect($doc->status)->toBe(IngestaStatus::DescartadoAuto)
        ->and($doc->kind)->toBe(PolicyDocumentKind::Otro)
        ->and(data_get($doc->payload, 'extraccion.razon_descarte'))->toBe('cotizacion');
});

it('valida cada campo determinísticamente: nunca confía ciego en el LLM', function (): void {
    IngestaExtractorAgent::fake([json_encode([
        'clase' => 'poliza',
        'compania' => 'Sancor',
        'numero_poliza' => '21', // < 5 caracteres alfanuméricos → inválido
        'endoso_numero' => null,
        'tomador' => [
            'tipo_persona' => 'fisica', 'first_name' => 'X', 'last_name' => 'Y', 'razon_social' => null,
            'documento_tipo' => 'DNI',
            'documento_numero' => '30500049460', // CUIT de Sancor (emisor), no del tomador
        ],
        'riesgo' => ['patente' => 'ABC123XYZ', 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null], // patente con formato inválido
        'fechas' => ['emision' => null, 'vigencia_desde' => null, 'vigencia_hasta' => '1999-01-01'], // año fuera de rango
    ])]);

    $doc = runIngestaJob(extractionStagedDoc());

    expect($doc->status)->toBe(IngestaStatus::Pendiente) // clase poliza sigue cayendo a pendiente
        ->and($doc->numero_poliza)->toBeNull()
        ->and($doc->documento_numero)->toBeNull()
        ->and($doc->patente)->toBeNull()
        ->and(data_get($doc->payload, 'fechas.vigencia_hasta'))->toBeNull()
        ->and($doc->compania)->toBe('Sancor Seguros') // esto sí es válido: alias normalizado
        ->and($doc->campos_no_extraidos)->toContain('numero_poliza', 'documento_numero', 'patente', 'vigencia_hasta');
});

it('trata una clase desconocida como corpus conservador: pendiente, kind otro', function (): void {
    IngestaExtractorAgent::fake([json_encode([
        'clase' => 'algo_que_el_prompt_no_contempla',
        'compania' => null, 'numero_poliza' => null, 'endoso_numero' => null,
        'tomador' => ['tipo_persona' => null, 'first_name' => null, 'last_name' => null, 'razon_social' => null, 'documento_tipo' => null, 'documento_numero' => null],
        'riesgo' => ['patente' => null, 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null],
        'fechas' => ['emision' => null, 'vigencia_desde' => null, 'vigencia_hasta' => null],
    ])]);

    $doc = runIngestaJob(extractionStagedDoc());

    expect($doc->status)->toBe(IngestaStatus::Pendiente)
        ->and($doc->kind)->toBe(PolicyDocumentKind::Otro);
});

it('corrige la compañía por CUIT del emisor en el texto (cupón que no nombra a la compañía)', function (): void {
    // Caso real 2026-07-14 (ingested_document 171): el cupón de pago de San Cristóbal
    // no menciona el nombre de la compañía en el texto, solo su CUIT — el LLM adivinó
    // "Sancor Seguros" y rompió la agrupación del contrato. El CUIT es señal más fuerte.
    IngestaExtractorAgent::fake([json_encode([
        'clase' => 'cupon',
        'compania' => 'Sancor Seguros', // lo que el LLM adivinó (mal)
        'numero_poliza' => '01-03-07-30414411', 'endoso_numero' => null,
        'tomador' => ['tipo_persona' => 'fisica', 'first_name' => 'RODRIGO EZEQUIEL', 'last_name' => 'BASSI', 'razon_social' => null, 'documento_tipo' => null, 'documento_numero' => null],
        'riesgo' => ['patente' => null, 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null],
        'fechas' => ['emision' => null, 'vigencia_desde' => '2026-04-16', 'vigencia_hasta' => '2026-07-16'],
    ])]);

    $doc = runIngestaJob(extractionStagedDoc([
        'payload' => [
            'schema_version' => 2,
            'archivo' => ['nombre_original' => 'cupones_de_pago.pdf', 'hash_sha256' => str_repeat('b', 64), 'detectado_en' => null],
            // Texto real del cupón: CUIT del emisor (San Cristóbal), sin el nombre.
            'texto' => "C.U.I.T. 34-50004533-9 C.U.I.T. 34-50004533-9\nInscrip. SSN 0192\nPÓLIZA Nro 01-03-07-30414411\nRAMO COMBINADO BASSI RODRIGO EZEQUIEL",
        ],
    ]));

    expect($doc->compania)->toBe('San Cristóbal') // el CUIT pisa al LLM
        ->and($doc->kind)->toBe(PolicyDocumentKind::Cupon)
        ->and($doc->status)->toBe(IngestaStatus::Pendiente);
});

it('no pisa la compañía cuando el texto trae CUITs de más de una aseguradora (ambiguo)', function (): void {
    IngestaExtractorAgent::fake([json_encode([
        'clase' => 'certificado',
        'compania' => 'Triunfo', // lo que dijo el LLM
        'numero_poliza' => '1912367', 'endoso_numero' => null,
        'tomador' => ['tipo_persona' => null, 'first_name' => null, 'last_name' => null, 'razon_social' => null, 'documento_tipo' => null, 'documento_numero' => null],
        'riesgo' => ['patente' => null, 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null],
        'fechas' => ['emision' => null, 'vigencia_desde' => null, 'vigencia_hasta' => null],
    ])]);

    $doc = runIngestaJob(extractionStagedDoc([
        'payload' => [
            'schema_version' => 2,
            'archivo' => ['nombre_original' => 'tarjeta_mercosur.pdf', 'hash_sha256' => str_repeat('c', 64), 'detectado_en' => null],
            // Tarjeta verde Mercosur: lista varias aseguradoras representantes con sus CUITs.
            'texto' => "Representantes: SANCOR SEGUROS C.U.I.T. 30-50004946-0 / SAN CRISTOBAL C.U.I.T. 34-50004533-9\nPÓLIZA 1912367",
        ],
    ]));

    // Ambiguo → conserva lo del LLM (normalizado por alias).
    expect($doc->compania)->toBe('Triunfo Cooperativa de Seguros');
});

it('clasifica un endoso/cancelación como tal (no como poliza)', function (): void {
    IngestaExtractorAgent::fake([json_encode([
        'clase' => 'endoso',
        'compania' => 'Triunfo Cooperativa de Seguros', 'numero_poliza' => '1912367', 'endoso_numero' => '3',
        'tomador' => ['tipo_persona' => 'fisica', 'first_name' => 'ELIO', 'last_name' => 'MOREIRA', 'razon_social' => null, 'documento_tipo' => 'DNI', 'documento_numero' => '26919664'],
        'riesgo' => ['patente' => 'VDO674', 'marca' => null, 'modelo' => null, 'year' => null, 'combustible' => null, 'uso' => null],
        'fechas' => ['emision' => null, 'vigencia_desde' => null, 'vigencia_hasta' => null],
    ])]);

    $doc = runIngestaJob(extractionStagedDoc());

    expect($doc->status)->toBe(IngestaStatus::Pendiente)
        ->and($doc->kind)->toBe(PolicyDocumentKind::Endoso);
});

it('degrada a pendiente sin llamar al LLM cuando no hay texto en el payload', function (): void {
    IngestaExtractorAgent::fake()->preventStrayPrompts();

    $doc = runIngestaJob(extractionStagedDoc([
        'payload' => [
            'schema_version' => 2,
            'archivo' => ['nombre_original' => 'sin-texto.pdf', 'hash_sha256' => str_repeat('e', 64), 'detectado_en' => null],
            'texto' => null,
        ],
    ]));

    expect($doc->status)->toBe(IngestaStatus::Pendiente)
        ->and($doc->numero_poliza)->toBeNull()
        ->and(data_get($doc->payload, 'extraccion.clase'))->toBe('sin_texto');

    IngestaExtractorAgent::assertNeverPrompted();
});

it('lanza cuando el LLM no devuelve JSON parseable (el worker reintentará)', function (): void {
    IngestaExtractorAgent::fake(['esto no es JSON, el modelo se fue por las ramas.']);

    expect(fn () => runIngestaJob(extractionStagedDoc()))->toThrow(RuntimeException::class);
});

it('failed() degrada la fila a pendiente tras agotar reintentos', function (): void {
    $doc = extractionStagedDoc();
    $job = new ExtractIngestedDocument($doc);

    $job->failed(new RuntimeException('simulado: agotados los reintentos'));

    expect($doc->fresh()->status)->toBe(IngestaStatus::Pendiente);
});

it('no idempotente hacia atrás: un documento ya resuelto no se re-extrae ni re-llama al LLM', function (): void {
    IngestaExtractorAgent::fake()->preventStrayPrompts();

    $doc = extractionStagedDoc(['status' => IngestaStatus::Confirmado]);

    runIngestaJob($doc);

    expect($doc->fresh()->status)->toBe(IngestaStatus::Confirmado);
    IngestaExtractorAgent::assertNeverPrompted();
});

it('E2E: sube por el endpoint real, el job (corrido a mano) deja pendiente y se confirma materializando la cadena', function (): void {
    Storage::fake('r2');
    Queue::fake();
    IngestaExtractorAgent::fake([fixtureSancorPoliza()]);
    Sanctum::actingAs(User::factory()->create());

    $hash = str_repeat('f', 64);

    $response = test()->post('/api/ingesta/documentos', [
        'metadata' => json_encode([
            'schema_version' => 2,
            'archivo' => ['nombre_original' => 'Caratula Anual (5).pdf', 'hash_sha256' => $hash, 'detectado_en' => '2026-06-24T08:00:00-03:00'],
            'texto' => 'SANCOR SEGUROS - Póliza N° 000031184413 - Asegurado: SICOT LEONARDO FABIO - Dominio: AB235OR',
        ]),
        'file' => UploadedFile::fake()->create('poliza.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $doc = IngestedDocument::findOrFail($response->json('ingested_document_id'));
    // El endpoint encola la extracción y contesta: no la corre adentro del request.
    expect($doc->status)->toBe(IngestaStatus::EnExtraccion);
    Queue::assertPushedOn('documents', ExtractIngestedDocument::class);

    // Simula al worker procesando el job encolado.
    $doc = runIngestaJob($doc);
    expect($doc->status)->toBe(IngestaStatus::Pendiente);

    app(IngestaConfirmacionService::class)->confirm($doc);

    $doc = $doc->fresh();
    expect($doc->status->value)->toBe('confirmado')
        ->and($doc->poliza_id)->not->toBeNull()
        ->and($doc->policy_document_id)->not->toBeNull();

    $poliza = $doc->poliza;
    expect($poliza->numero)->toBe('000031184413')
        ->and($poliza->company)->toBe('Sancor Seguros')
        ->and($poliza->risk->customer->dni)->toBe('21407965');
});
