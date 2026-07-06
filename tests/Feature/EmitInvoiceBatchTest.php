<?php

use App\Enums\InvoiceEstado;
use App\Jobs\EmitInvoiceBatch;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\Afip\AfipEmisionException;
use App\Services\Afip\AfipSoapService;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('emite el lote: una autoriza, otra rechaza, y el lote NO se detiene', function (): void {
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
});

it('cierra limpiamente un lote sin facturas pendientes (idempotencia en reintento)', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldNotReceive('autorizar');

    (new EmitInvoiceBatch($batch->id, 2))->handle($afip);

    expect($batch->refresh()->estado)->toBe('completed');
});

it('genera el PDF al vuelo (sin persistir) con nombre FC-{ptovta}-{numero}.pdf', function (): void {
    config(['afip.cuit' => '20111111112']);

    $invoice = Invoice::factory()->authorized()->create([
        'pto_vta' => 3,
        'numero_comprobante' => 8,
        'cae' => '71234567890123',
    ]);

    $service = app(InvoicePdfService::class);
    $bytes = $service->generar($invoice);

    expect($bytes)->toStartWith('%PDF-')
        ->and($service->filename($invoice))->toBe('FC-3-00000008.pdf');
});
