<?php

use App\Enums\InvoiceEstado;
use App\Events\InvoiceAuthorized;
use App\Events\InvoiceRejected;
use App\Jobs\EmitInvoiceBatch;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\Afip\AfipEmisionException;
use App\Services\Afip\AfipSoapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('emite el lote: una autoriza, otra rechaza, y el lote NO se detiene', function (): void {
    Event::fake([InvoiceAuthorized::class, InvoiceRejected::class]);

    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $ok = Invoice::factory()->for($batch, 'batch')->create(['estado' => InvoiceEstado::Pending, 'pto_vta' => 2]);
    $bad = Invoice::factory()->for($batch, 'batch')->create(['estado' => InvoiceEstado::Pending, 'pto_vta' => 2]);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldReceive('ultimoAutorizado')->once()->with(2, 11)->andReturn(100);
    $afip->shouldReceive('autorizar')->andReturnUsing(function (Invoice $invoice, int $numero) use ($bad): array {
        if ($invoice->id === $bad->id) {
            throw new AfipEmisionException('CUIT del receptor inexistente');
        }

        return ['numero' => $numero, 'cae' => '71234567890123', 'cae_vencimiento' => now()->addDays(10)->format('Ymd')];
    });

    (new EmitInvoiceBatch($batch->id, 2))->handle($afip);

    $ok->refresh();
    $bad->refresh();
    $batch->refresh();

    expect($ok->estado)->toBe(InvoiceEstado::Authorized)
        ->and($ok->cae)->toBe('71234567890123')
        ->and($ok->numero_comprobante)->toBe(101);

    expect($bad->estado)->toBe(InvoiceEstado::Rejected)
        ->and($bad->observaciones)->toContain('inexistente')
        ->and($bad->numero_comprobante)->toBeNull(); // número no consumido en el rechazo

    expect($batch->estado)->toBe('completed')
        ->and($batch->finished_at)->not->toBeNull()
        ->and($batch->summary['autorizadas'])->toBe(1)
        ->and($batch->summary['rechazadas'])->toBe(1)
        ->and($batch->summary['total'])->toBe(2);

    Event::assertDispatched(InvoiceAuthorized::class);
    Event::assertDispatched(InvoiceRejected::class);
});

it('cierra limpiamente un lote sin facturas pendientes (idempotencia en reintento)', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldNotReceive('autorizar');

    (new EmitInvoiceBatch($batch->id, 2))->handle($afip);

    expect($batch->refresh()->estado)->toBe('completed');
});

it('genera el PDF al autorizar (listener sincrónico)', function (): void {
    Storage::fake('local');
    config(['afip.cuit' => '20111111112']);

    $invoice = Invoice::factory()->authorized()->create([
        'pdf_path' => null,
        'numero_comprobante' => 101,
        'cae' => '71234567890123',
    ]);

    event(new InvoiceAuthorized($invoice));

    $invoice->refresh();
    expect($invoice->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($invoice->pdf_path);
});
