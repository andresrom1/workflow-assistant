<?php

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Jobs\CapturePendingPolicyDocuments;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\PolizaProviderRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('crea la referencia y encola el job de recaptura', function (): void {
    Queue::fake();
    $poliza = Poliza::factory()->create();

    $this->artisan('policy:recapture-documents', [
        'poliza_id' => $poliza->id,
        'document_token' => '32095',
        '--product-id' => 'auto',
        '--kinds' => 'poliza,certificado',
    ])->assertSuccessful();

    $ref = PolizaProviderRef::query()->where('poliza_id', $poliza->id)->first();

    expect($ref)->not->toBeNull()
        ->and($ref->document_token)->toBe('32095')
        ->and($ref->product_id)->toBe('auto')
        ->and($ref->pending_document_kinds)->toBe(['poliza', 'certificado']);

    Queue::assertPushed(CapturePendingPolicyDocuments::class, fn ($job): bool => $job->polizaId === $poliza->id);
});

it('falla si la póliza no existe', function (): void {
    $this->artisan('policy:recapture-documents', [
        'poliza_id' => 9999,
        'document_token' => '32095',
    ])->assertFailed();
});

it('falla si los kinds son inválidos', function (): void {
    $poliza = Poliza::factory()->create();

    $this->artisan('policy:recapture-documents', [
        'poliza_id' => $poliza->id,
        'document_token' => '32095',
        '--kinds' => 'poliza,invalido',
    ])->assertFailed();
});

it('saltea los kinds que ya fueron capturados desde emisión', function (): void {
    Queue::fake();
    $poliza = Poliza::factory()->create();

    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => PolicyDocumentKind::Poliza->value,
        'storage_path' => 'policy-documents/test.pdf',
        'source' => PolicyDocumentSource::VisredEmission,
        'captured_at' => now(),
    ]);

    $this->artisan('policy:recapture-documents', [
        'poliza_id' => $poliza->id,
        'document_token' => '32095',
        '--kinds' => 'poliza,certificado',
    ])->assertSuccessful();

    $ref = PolizaProviderRef::query()->where('poliza_id', $poliza->id)->first();

    expect($ref->pending_document_kinds)->toBe(['certificado']);
    Queue::assertPushed(CapturePendingPolicyDocuments::class);
});

it('avisa y no encola nada si todos los documentos ya existen', function (): void {
    Queue::fake();
    $poliza = Poliza::factory()->create();

    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => PolicyDocumentKind::Poliza->value,
        'storage_path' => 'policy-documents/test.pdf',
        'source' => PolicyDocumentSource::VisredEmission,
        'captured_at' => now(),
    ]);

    $this->artisan('policy:recapture-documents', [
        'poliza_id' => $poliza->id,
        'document_token' => '32095',
        '--kinds' => 'poliza',
    ])->assertSuccessful();

    Queue::assertNothingPushed();
});
