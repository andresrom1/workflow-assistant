<?php

use App\Models\CoverageDocument;
use App\Services\ChunkAndEmbedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * El ida y vuelta a `.md` con el que se curan los manuales a mano.
 *
 * Se mockea `ChunkAndEmbedService` porque genera embeddings contra un proveedor externo: lo que
 * se verifica aca es el transporte del texto, no la indexacion.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->dir = storage_path('framework/testing/coverage-md-'.uniqid());
    File::ensureDirectoryExists($this->dir);

    $this->mock(ChunkAndEmbedService::class, function ($mock): void {
        $mock->shouldReceive('execute')->andReturn(7);
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function documentoDeCobertura(array $extra = []): CoverageDocument
{
    return CoverageDocument::create([
        'company_slug' => 'triunfo',
        'company_name' => 'Triunfo',
        'document_type' => 'manual',
        'original_filename' => 'manual.pdf',
        'storage_path' => 'coverage-documents/triunfo/manual.pdf',
        'storage_disk' => 'local',
        'mime_type' => 'application/pdf',
        'extracted_content' => "## C2 FULL\n\n| Cobertura | Tope |\n|---|---|\n| Granizo | 500.000 |",
        'extraction_status' => 'completed',
        'extraction_mode' => 'manual',
        'version' => '04-2026',
        'is_active' => true,
        ...$extra,
    ]);
}

it('exporta el texto con encabezado que identifica al documento', function (): void {
    documentoDeCobertura();

    $this->artisan('coverage:export', ['--dir' => $this->dir])->assertSuccessful();

    $md = File::get($this->dir.'/triunfo--manual.md');

    expect($md)
        ->toContain('company_name: Triunfo')
        ->toContain('document_type: manual')
        ->toContain('version: 04-2026')
        ->toContain('## C2 FULL');
});

it('no exporta los documentos deprecados salvo que se pidan', function (): void {
    documentoDeCobertura(['is_active' => false]);

    $this->artisan('coverage:export', ['--dir' => $this->dir])->assertSuccessful();
    expect(File::exists($this->dir.'/triunfo--manual.md'))->toBeFalse();

    $this->artisan('coverage:export', ['--dir' => $this->dir, '--all' => true])->assertSuccessful();
    expect(File::exists($this->dir.'/triunfo--manual.md'))->toBeTrue();
});

it('devuelve el texto curado al documento que le corresponde', function (): void {
    $doc = documentoDeCobertura();

    $this->artisan('coverage:export', ['--dir' => $this->dir])->assertSuccessful();

    $md = File::get($this->dir.'/triunfo--manual.md');
    File::put($this->dir.'/triunfo--manual.md', str_replace('500.000', '750.000', $md));

    $this->artisan('coverage:import', ['path' => $this->dir])->assertSuccessful();

    expect($doc->fresh()->extracted_content)->toContain('750.000');
    expect(CoverageDocument::count())->toBe(1);
});

it('el ida y vuelta no altera el texto', function (): void {
    $doc = documentoDeCobertura();
    $antes = $doc->extracted_content;

    $this->artisan('coverage:export', ['--dir' => $this->dir])->assertSuccessful();
    $this->artisan('coverage:import', ['path' => $this->dir])->assertSuccessful();

    expect($doc->fresh()->extracted_content)->toBe($antes);
});

it('crea el documento cuando no existe, que es el caso de produccion', function (): void {
    File::put($this->dir.'/sancor--manual.md', <<<'MD'
        ---
        company_name: Sancor Seguros
        document_type: manual
        version: 2026-01
        original_filename: sancor.pdf
        ---

        ## Auto Max 15

        Texto curado a mano.
        MD);

    $this->artisan('coverage:import', ['path' => $this->dir])->assertSuccessful();

    $creado = CoverageDocument::where('company_slug', 'sancor-seguros')->sole();

    expect($creado->document_type)->toBe('manual')
        ->and($creado->extraction_status)->toBe('completed')
        ->and($creado->extraction_mode)->toBe('manual')
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->extracted_content)->toContain('Texto curado a mano.');
});

it('no escribe nada en modo seco', function (): void {
    $doc = documentoDeCobertura();

    $this->artisan('coverage:export', ['--dir' => $this->dir])->assertSuccessful();

    $md = File::get($this->dir.'/triunfo--manual.md');
    File::put($this->dir.'/triunfo--manual.md', str_replace('500.000', '750.000', $md));

    $this->artisan('coverage:import', ['path' => $this->dir, '--dry-run' => true])->assertSuccessful();

    expect($doc->fresh()->extracted_content)->toContain('500.000');
});

it('rechaza un archivo sin encabezado en vez de crear un documento sin dueno', function (): void {
    File::put($this->dir.'/suelto.md', '# Un manual sin encabezado');

    $this->artisan('coverage:import', ['path' => $this->dir])->assertFailed();

    expect(CoverageDocument::count())->toBe(0);
});

it('rechaza un archivo con el cuerpo vacio en vez de borrar el texto bueno', function (): void {
    $doc = documentoDeCobertura();

    File::put($this->dir.'/triunfo--manual.md', "---\ncompany_name: Triunfo\ndocument_type: manual\n---\n\n   \n");

    $this->artisan('coverage:import', ['path' => $this->dir])->assertFailed();

    expect($doc->fresh()->extracted_content)->toContain('500.000');
});

it('guarda el texto aunque falle el indexado', function (): void {
    // El agente lee `extracted_content`; los chunks solo alimentan la búsqueda por similitud,
    // que depende de un proveedor externo. El free tier de Gemini corta por cuota a mitad de una
    // carga de diez documentos, y eso no puede dejar los manuales a medio cargar.
    $this->mock(ChunkAndEmbedService::class, function ($mock): void {
        $mock->shouldReceive('execute')->andThrow(new RuntimeException('rate limited'));
    });

    File::put($this->dir.'/triunfo--manual.md', <<<'MD'
        ---
        company_name: Triunfo
        document_type: manual
        ---

        ## C2 FULL

        Texto curado.
        MD);

    $this->artisan('coverage:import', ['path' => $this->dir])
        ->expectsOutputToContain('el indexado falló')
        ->expectsOutputToContain('coverage:re-embed --id=')
        ->assertSuccessful();

    expect(CoverageDocument::where('company_slug', 'triunfo')->sole()->extracted_content)
        ->toContain('Texto curado.');
});
