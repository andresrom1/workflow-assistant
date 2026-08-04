<?php

use App\Enums\InvoiceEstado;
use App\Jobs\CloseInvoiceBatch;
use App\Jobs\EmitInvoice;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\Afip\AfipEmisionException;
use App\Services\Afip\AfipRespuestaIndeterminadaException;
use App\Services\Afip\AfipSoapService;
use App\Services\InvoicePdfService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pendiente(InvoiceBatch $batch, array $attrs = []): Invoice
{
    return Invoice::factory()->for($batch, 'batch')->create(array_merge([
        'estado' => InvoiceEstado::Pending,
        'pto_vta' => 2,
        'tipo_comprobante' => 11,
    ], $attrs));
}

it('reserva el número antes de emitir y guarda el CAE al autorizar', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldReceive('ultimoAutorizado')->once()->with(2, 11)->andReturn(100);
    $afip->shouldReceive('autorizar')->once()->andReturnUsing(function (Invoice $i, int $numero): array {
        // Para cuando AFIP contesta, la reserva ya tiene que estar persistida: es lo único que
        // permite reconciliar si el proceso muere en este punto.
        expect($i->fresh()->numero_reservado)->toBe(101);

        return ['numero' => $numero, 'cae' => '71234567890123', 'cae_vencimiento' => now()->addDays(10)->format('Ymd')];
    });

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    expect($invoice->fresh()->estado)->toBe(InvoiceEstado::Authorized)
        ->and($invoice->fresh()->numero_comprobante)->toBe(101)
        ->and($invoice->fresh()->cae)->toBe('71234567890123');
});

it('un rechazo de AFIP libera el número reservado (no se consume)', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldReceive('ultimoAutorizado')->once()->andReturn(100);
    $afip->shouldReceive('autorizar')->once()->andThrow(new AfipEmisionException('CUIT del receptor inexistente'));

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    $invoice->refresh();

    expect($invoice->estado)->toBe(InvoiceEstado::Rejected)
        ->and($invoice->observaciones)->toContain('inexistente')
        ->and($invoice->numero_comprobante)->toBeNull()
        ->and($invoice->numero_reservado)->toBeNull(); // liberado para el comprobante siguiente
});

/**
 * La regresión del incidente del 2026-08-04: AFIP autorizó, el proceso murió antes de persistir el
 * CAE. El reintento NO debe volver a emitir — debe adoptar el comprobante que ya existe.
 */
it('reconcilia una emisión en duda sin volver a emitir', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch, [
        'numero_reservado' => 18,
        'receptor_cuit' => '30500000127',
        'importe' => 162739.46,
    ]);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldNotReceive('autorizar');
    $afip->shouldNotReceive('ultimoAutorizado');
    $afip->shouldReceive('consultar')->once()->with(2, 11, 18)->andReturn([
        'numero' => 18,
        'cae' => '86316795766012',
        'cae_vencimiento' => '20260814',
        'doc_nro' => '30500000127',
        'imp_total' => '162739.46',
        'resultado' => 'A',
    ]);

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    $invoice->refresh();

    expect($invoice->estado)->toBe(InvoiceEstado::Authorized)
        ->and($invoice->numero_comprobante)->toBe(18)
        ->and($invoice->cae)->toBe('86316795766012');
});

it('emite con el número reservado cuando AFIP no lo tiene', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch, ['numero_reservado' => 55]);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldReceive('consultar')->once()->with(2, 11, 55)->andReturnNull();
    // No vuelve a pedir numeración: reusa la reserva, así no deja un hueco en la correlativa.
    $afip->shouldNotReceive('ultimoAutorizado');
    $afip->shouldReceive('autorizar')->once()->with(Mockery::any(), 55)->andReturn([
        'numero' => 55, 'cae' => '71234567890123', 'cae_vencimiento' => now()->addDays(10)->format('Ymd'),
    ]);

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    expect($invoice->fresh()->numero_comprobante)->toBe(55);
});

it('no adopta un comprobante ajeno: lo marca para revisión manual', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch, [
        'numero_reservado' => 18,
        'receptor_cuit' => '30500000127',
        'importe' => 162739.46,
    ]);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldNotReceive('autorizar');
    $afip->shouldReceive('consultar')->once()->andReturn([
        'numero' => 18,
        'cae' => '99999999999999',
        'cae_vencimiento' => '20260814',
        'doc_nro' => '30999999999', // otro receptor
        'imp_total' => '162739.46',
        'resultado' => 'A',
    ]);

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    $invoice->refresh();

    expect($invoice->estado)->toBe(InvoiceEstado::Rejected)
        ->and($invoice->cae)->toBeNull() // nunca se pisa el CAE de otro comprobante
        ->and($invoice->observaciones)->toContain('revisión manual');
});

it('ante una respuesta indeterminada no adivina: deja la reserva y propaga', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = pendiente($batch);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldReceive('ultimoAutorizado')->once()->andReturn(100);
    $afip->shouldReceive('autorizar')->once()->andThrow(new AfipRespuestaIndeterminadaException('Respuesta ilegible de AFIP'));

    expect(fn () => (new EmitInvoice($invoice->id, 2))->handle($afip))
        ->toThrow(AfipRespuestaIndeterminadaException::class);

    $invoice->refresh();

    // Sigue Pending con la reserva intacta: el reintento va a consultar el 101 y resolver de verdad.
    expect($invoice->estado)->toBe(InvoiceEstado::Pending)
        ->and($invoice->numero_reservado)->toBe(101);
});

it('no reprocesa una factura que ya no está pendiente', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    $invoice = Invoice::factory()->authorized()->for($batch, 'batch')->create(['pto_vta' => 2]);

    $afip = Mockery::mock(AfipSoapService::class);
    $afip->shouldNotReceive('autorizar');
    $afip->shouldNotReceive('consultar');

    (new EmitInvoice($invoice->id, 2))->handle($afip);

    expect($invoice->fresh()->estado)->toBe(InvoiceEstado::Authorized);
});

it('el cierre deja el lote en estado terminal con los conteos', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    Invoice::factory()->authorized()->for($batch, 'batch')->create(['pto_vta' => 2, 'numero_comprobante' => 1]);
    pendiente($batch, ['estado' => InvoiceEstado::Rejected]);

    (new CloseInvoiceBatch($batch->id))->handle();

    $batch->refresh();

    expect($batch->estado)->toBe('completed')
        ->and($batch->finished_at)->not->toBeNull()
        ->and($batch->summary['autorizadas'])->toBe(1)
        ->and($batch->summary['rechazadas'])->toBe(1)
        ->and($batch->summary['total'])->toBe(2);
});

it('el cierre por falla marca el lote failed, nunca lo deja en processing', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);

    (new CloseInvoiceBatch($batch->id, 'failed'))->handle();

    expect($batch->fresh()->estado)->toBe('failed')
        ->and($batch->fresh()->finished_at)->not->toBeNull();
});

it('la base impide que dos comprobantes reserven el mismo número', function (): void {
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'processing']);
    pendiente($batch, ['numero_reservado' => 7]);

    expect(fn () => pendiente($batch, ['numero_reservado' => 7]))
        ->toThrow(UniqueConstraintViolationException::class);
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
