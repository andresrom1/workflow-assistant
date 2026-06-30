<?php

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Enums\PolizaEstado;
use App\Jobs\PublishDocumentAvailable;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

it('el gestor expone el checklist de completitud (presentes vs faltantes)', function (): void {
    $poliza = Poliza::factory()->create();
    // Tiene la Póliza; faltan Cédula de circulación y Certificado.
    PolicyDocument::factory()->adminUpload()->create([
        'poliza_id' => $poliza->id,
        'kind' => PolicyDocumentKind::Poliza,
    ]);

    $this->actingAs($this->user)
        ->get(route('policy-documents.show', $poliza))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/Show')
            ->has('checklist', 3)
            ->where('checklist.0.kind', 'poliza')
            ->where('checklist.0.presente', true)
            ->where('checklist.1.presente', false)
            ->where('checklist.2.presente', false));
});

it('el gestor preselecciona el kind cuando se entra con ?kind=', function (): void {
    $poliza = Poliza::factory()->create();

    $this->actingAs($this->user)
        ->get(route('policy-documents.show', [$poliza, 'kind' => PolicyDocumentKind::CirculationCard->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/Show')
            ->where('preselectKind', 'circulation-card'));

    // Un kind inválido no preselecciona nada.
    $this->actingAs($this->user)
        ->get(route('policy-documents.show', [$poliza, 'kind' => 'inexistente']))
        ->assertInertia(fn ($page) => $page->where('preselectKind', null));
});

it('el panel de pendientes lista vigentes y emitidas incompletas, excluye completas y vencidas', function (): void {
    // Vigente incompleta (solo Póliza, faltan 2) → aparece.
    $incompleta = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'numero' => 'POL-INC']);
    PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $incompleta->id, 'kind' => PolicyDocumentKind::Poliza]);

    // Emitida sin documentos → aparece.
    Poliza::factory()->create(['estado' => PolizaEstado::Emitida, 'numero' => 'POL-EMI']);

    // Vigente COMPLETA (los 3 esperados) → NO aparece.
    $completa = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'numero' => 'POL-OK']);
    foreach ([PolicyDocumentKind::Poliza, PolicyDocumentKind::CirculationCard, PolicyDocumentKind::Certificado] as $k) {
        PolicyDocument::factory()->adminUpload()->create(['poliza_id' => $completa->id, 'kind' => $k]);
    }

    // Vencida incompleta → fuera de alcance, NO aparece.
    Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'numero' => 'POL-VEN']);

    $this->actingAs($this->user)
        ->get(route('documentacion-pendiente'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/Pendientes')
            ->where('polizas.total', 2)
            ->has('polizas.data', 2));
});

it('el panel de pendientes pagina', function (): void {
    // 3 vigentes sin documentos → todas pendientes.
    Poliza::factory()->count(3)->create(['estado' => PolizaEstado::Vigente]);

    $this->actingAs($this->user)
        ->get(route('documentacion-pendiente', ['per_page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PolicyDocuments/Pendientes')
            ->where('polizas.total', 3)
            ->where('polizas.last_page', 2)
            ->has('polizas.data', 2));
});

it('el index expone la completitud de documentación esperada', function (): void {
    $poliza = Poliza::factory()->create(['numero' => 'POL-CHK']);
    PolicyDocument::factory()->adminUpload()->create([
        'poliza_id' => $poliza->id,
        'kind' => PolicyDocumentKind::Poliza,
    ]);

    $this->actingAs($this->user)
        ->get(route('policy-documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('polizas.data.0.doc_presentes', 1)
            ->where('polizas.data.0.doc_esperados', 3));
});

it('sube un documento manual y lo persiste en R2 como admin_upload', function (): void {
    $poliza = Poliza::factory()->create();
    $file = UploadedFile::fake()->create('endoso-junio.pdf', 200, 'application/pdf');

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => $file,
            'kind' => PolicyDocumentKind::Endoso->value,
            'label' => 'Endoso cambio de uso',
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

it('encola el aviso push al titular con cuenta al cargar un documento a una póliza vigente', function (): void {
    Queue::fake();
    $customer = Customer::factory()->create(['email' => 'asegurado@example.com']);
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);
    $poliza = Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vigente]);
    $account = MobileAccount::factory()->create(['email' => 'asegurado@example.com']);

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => UploadedFile::fake()->create('poliza.pdf', 100, 'application/pdf'),
            'kind' => PolicyDocumentKind::Poliza->value,
        ])
        ->assertRedirect();

    Queue::assertPushed(PublishDocumentAvailable::class, fn (PublishDocumentAvailable $job): bool => $job->mobileAccountId === $account->id
        && $job->polizaId === $poliza->id
        && $job->kind === 'poliza');
});

it('no encola el aviso si la póliza no está vigente', function (): void {
    Queue::fake();
    $customer = Customer::factory()->create(['email' => 'asegurado@example.com']);
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);
    $poliza = Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vencida]);
    MobileAccount::factory()->create(['email' => 'asegurado@example.com']);

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => UploadedFile::fake()->create('poliza.pdf', 100, 'application/pdf'),
            'kind' => PolicyDocumentKind::Poliza->value,
        ])->assertRedirect();

    Queue::assertNotPushed(PublishDocumentAvailable::class);
});

it('no encola el aviso si el titular no tiene cuenta en la app', function (): void {
    Queue::fake();
    $customer = Customer::factory()->create(['email' => 'sincuenta@example.com']);
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);
    $poliza = Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vigente]);

    $this->actingAs($this->user)
        ->post(route('policy-documents.store', $poliza), [
            'file' => UploadedFile::fake()->create('poliza.pdf', 100, 'application/pdf'),
            'kind' => PolicyDocumentKind::Poliza->value,
        ])->assertRedirect();

    Queue::assertNotPushed(PublishDocumentAvailable::class);
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
