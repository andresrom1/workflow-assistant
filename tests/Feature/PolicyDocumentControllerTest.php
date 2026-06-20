<?php

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Storage::fake('r2');
});

it('requiere autenticación', function (): void {
    $poliza = Poliza::factory()->create();

    $this->get(route('policy-documents.show', $poliza))
        ->assertRedirect('/login');
});

it('renderiza el index con el buscador de pólizas', function (): void {
    Poliza::factory()->create(['numero' => 'POL-555']);

    $this->actingAs($this->user)
        ->get(route('policy-documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('PolicyDocuments/Index'));
});

it('filtra el index por número de póliza', function (): void {
    Poliza::factory()->create(['numero' => 'POL-AAA']);
    Poliza::factory()->create(['numero' => 'POL-BBB']);

    $this->actingAs($this->user)
        ->get(route('policy-documents.index', ['search' => 'AAA']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/Index')
            ->has('polizas.data', 1)
            ->where('polizas.data.0.numero', 'POL-AAA'));
});

it('filtra el index por pólizas con documentos', function (): void {
    $conDocs = Poliza::factory()->create(['numero' => 'POL-CON']);
    PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $conDocs->id]);
    Poliza::factory()->create(['numero' => 'POL-SIN']);

    $this->actingAs($this->user)
        ->get(route('policy-documents.index', ['filter' => 'with']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('polizas.data', 1)
            ->where('polizas.data.0.numero', 'POL-CON')
            ->where('polizas.data.0.documents_count', 1));
});

it('filtra el index por pólizas sin documentos', function (): void {
    $conDocs = Poliza::factory()->create(['numero' => 'POL-CON']);
    PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $conDocs->id]);
    Poliza::factory()->create(['numero' => 'POL-SIN']);

    $this->actingAs($this->user)
        ->get(route('policy-documents.index', ['filter' => 'without']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('polizas.data', 1)
            ->where('polizas.data.0.numero', 'POL-SIN')
            ->where('polizas.data.0.documents_count', 0));
});

it('sube un documento manual y lo persiste en R2 como admin_upload', function (): void {
    $poliza = Poliza::factory()->create();
    $file = UploadedFile::fake()->create('endoso-junio.pdf', 200, 'application/pdf');

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => $file,
            'kind' => PolicyDocumentKind::Endoso->value,
            'label' => 'Endoso cambio de uso',
            'visible_to_client' => true,
        ])
        ->assertRedirect();

    $doc = PolicyDocument::firstOrFail();

    expect($doc->poliza_id)->toBe($poliza->id)
        ->and($doc->kind)->toBe(PolicyDocumentKind::Endoso)
        ->and($doc->source)->toBe(PolicyDocumentSource::AdminUpload)
        ->and($doc->original_filename)->toBe('endoso-junio.pdf')
        ->and($doc->label)->toBe('Endoso cambio de uso')
        ->and($doc->visible_to_client)->toBeTrue();

    expect($doc->storage_path)->toStartWith("policy-documents/{$poliza->id}/endoso-");
    Storage::disk('r2')->assertExists($doc->storage_path);
});

it('rechaza un archivo de tipo no permitido', function (): void {
    $poliza = Poliza::factory()->create();
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => $file,
            'kind' => PolicyDocumentKind::Otro->value,
        ])
        ->assertSessionHasErrors('file');

    expect(PolicyDocument::count())->toBe(0);
});

it('rechaza un kind inválido', function (): void {
    $poliza = Poliza::factory()->create();
    $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => $file,
            'kind' => 'inexistente',
        ])
        ->assertSessionHasErrors('kind');

    expect(PolicyDocument::count())->toBe(0);
});

it('togglea la visibilidad de un documento', function (): void {
    $doc = PolicyDocument::factory()->adminUpload()->create(['visible_to_client' => false]);

    $this->actingAs($this->user)
        ->patch(route('policy-documents.toggle-visibility', $doc))
        ->assertRedirect();

    expect($doc->fresh()->visible_to_client)->toBeTrue();

    $this->actingAs($this->user)
        ->patch(route('policy-documents.toggle-visibility', $doc))
        ->assertRedirect();

    expect($doc->fresh()->visible_to_client)->toBeFalse();
});

it('elimina el documento y borra el archivo en R2', function (): void {
    $poliza = Poliza::factory()->create();
    $path = "policy-documents/{$poliza->id}/endoso-".fake()->uuid().'.pdf';
    Storage::disk('r2')->put($path, 'contenido');

    $doc = PolicyDocument::factory()->adminUpload()->create([
        'poliza_id' => $poliza->id,
        'storage_path' => $path,
    ]);

    $this->actingAs($this->user)
        ->delete(route('policy-documents.destroy', $doc))
        ->assertRedirect();

    $this->assertModelMissing($doc);
    Storage::disk('r2')->assertMissing($path);
});
