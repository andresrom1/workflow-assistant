<?php

use App\Enums\InvoiceEstado;
use App\Jobs\CloseInvoiceBatch;
use App\Jobs\EmitInvoice;
use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function batchPayload(array $empresas): array
{
    return [
        'codigo' => '0006',
        'concepto' => 'Comisiones correspondientes a Junio 2026',
        'fecha_servicio_desde' => '2026-06-01',
        'fecha_servicio_hasta' => '2026-06-30',
        'fecha_vto_pago' => '2026-07-10',
        'empresas' => $empresas,
    ];
}

it('renderiza el index con las compañías, punto de venta y el mapa de duplicados', function (): void {
    $admin = User::factory()->admin()->create();
    $c1 = BillingCompany::factory()->create();
    BillingCompany::factory()->create(['activo' => false]); // no debe aparecer

    // Una factura ya autorizada bajo el código 0006 → alimenta facturadasPorCodigo.
    Invoice::factory()->authorized()->create(['billing_company_id' => $c1->id, 'codigo' => '0006']);

    $this->actingAs($admin)
        ->get(route('admin.facturacion.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Facturacion/Index')
            ->has('companies', 1) // solo la activa
            ->where('puntoVenta', 2)
            ->where('facturadasPorCodigo.0006', [$c1->id]));
});

it('crea el lote + facturas pending, snapshotea el receptor y despacha el job', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $c1 = BillingCompany::factory()->create();
    $c2 = BillingCompany::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.facturacion.store'), batchPayload([
            ['id' => $c1->id, 'importe' => 1500.50],
            ['id' => $c2->id, 'importe' => 2000],
        ]))
        ->assertRedirect();

    expect(InvoiceBatch::count())->toBe(1)
        ->and(Invoice::count())->toBe(2);

    $batch = InvoiceBatch::first();
    expect($batch->punto_venta)->toBe(2)
        ->and($batch->estado)->toBe('processing');

    $inv = Invoice::where('billing_company_id', $c1->id)->first();
    expect($inv->receptor_cuit)->toBe($c1->cuit)
        ->and($inv->receptor_razon_social)->toBe($c1->razon_social)
        ->and($inv->receptor_condicion_iva)->toBe('RI')
        ->and($inv->estado)->toBe(InvoiceEstado::Pending)
        ->and($inv->codigo)->toBe('0006')
        ->and($inv->pto_vta)->toBe(2)
        ->and((float) $inv->importe)->toBe(1500.50);

    // Un job de emisión por comprobante, y el cierre del lote como último eslabón.
    Bus::assertChained([
        new EmitInvoice(Invoice::orderBy('id')->first()->id, 2),
        new EmitInvoice(Invoice::orderBy('id')->skip(1)->first()->id, 2),
        new CloseInvoiceBatch($batch->id),
    ]);
});

it('rechaza un payload inválido', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.facturacion.store'), [
            'codigo' => '',
            'concepto' => '',
            'empresas' => [],
        ])
        ->assertInvalid(['codigo', 'concepto', 'fecha_servicio_desde', 'empresas']);

    expect(InvoiceBatch::count())->toBe(0);
});

it('rechaza una empresa tildada sin importe válido', function (): void {
    $admin = User::factory()->admin()->create();
    $c1 = BillingCompany::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.facturacion.store'), batchPayload([
            ['id' => $c1->id, 'importe' => 0],
        ]))
        ->assertInvalid(['empresas.0.importe']);
});

it('bloquea el acceso a usuarios no-admin', function (): void {
    $user = User::factory()->create();
    $c1 = BillingCompany::factory()->create();

    $this->actingAs($user)->get(route('admin.facturacion.index'))->assertForbidden();
    $this->actingAs($user)
        ->post(route('admin.facturacion.store'), batchPayload([['id' => $c1->id, 'importe' => 100]]))
        ->assertForbidden();
});

it('descarga un ZIP con los PDFs generados al vuelo de las facturas autorizadas', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->completed()->create();
    Invoice::factory()->authorized()->for($batch, 'batch')->create();
    Invoice::factory()->for($batch, 'batch')->create(['estado' => InvoiceEstado::Rejected]); // no debe entrar al zip

    $this->actingAs($admin)
        ->get(route('admin.facturacion.download', $batch))
        ->assertOk()
        ->assertDownload("facturas-lote-{$batch->codigo}-{$batch->id}.zip");
});

it('404 al descargar el ZIP de un lote sin facturas autorizadas', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->completed()->create();
    Invoice::factory()->for($batch, 'batch')->create(['estado' => InvoiceEstado::Rejected]);

    $this->actingAs($admin)->get(route('admin.facturacion.download', $batch))->assertNotFound();
});

it('descarga el PDF individual de una factura autorizada', function (): void {
    $admin = User::factory()->admin()->create();
    $invoice = Invoice::factory()->authorized()->create(['pto_vta' => 3, 'numero_comprobante' => 8]);

    $response = $this->actingAs($admin)->get(route('admin.facturacion.invoices.pdf', $invoice));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="FC-3-00000008.pdf"');
});

it('404 al pedir el PDF de una factura no autorizada', function (): void {
    $admin = User::factory()->admin()->create();
    $invoice = Invoice::factory()->create(['estado' => InvoiceEstado::Pending]);

    $this->actingAs($admin)->get(route('admin.facturacion.invoices.pdf', $invoice))->assertNotFound();
});

it('muestra el detalle de un lote con sus facturas', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->completed()->create(['summary' => ['autorizadas' => 1, 'rechazadas' => 0, 'total' => 1]]);
    Invoice::factory()->authorized()->for($batch, 'batch')->create();

    $this->actingAs($admin)
        ->get(route('admin.facturacion.show', $batch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Facturacion/BatchShow')
            ->where('batch.id', $batch->id)
            ->has('batch.invoices', 1));
});

it('reanudar un lote caído reencola solo los comprobantes pendientes', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->create([
        'punto_venta' => 2, 'estado' => 'failed', 'finished_at' => now(),
    ]);
    Invoice::factory()->authorized()->for($batch, 'batch')->create(['pto_vta' => 2, 'numero_comprobante' => 5]);
    $pendiente = Invoice::factory()->for($batch, 'batch')->create(['pto_vta' => 2, 'estado' => InvoiceEstado::Pending]);

    $this->actingAs($admin)
        ->post(route('admin.facturacion.resume', $batch))
        ->assertRedirect();

    $batch->refresh();
    expect($batch->estado)->toBe('processing')
        ->and($batch->finished_at)->toBeNull();

    // La autorizada NO se re-emite: solo entra a la cadena la que seguía pendiente.
    Bus::assertChained([
        new EmitInvoice($pendiente->id, 2),
        new CloseInvoiceBatch($batch->id),
    ]);
});

it('un lote fallido sigue visible en el index para poder destrabarlo', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->create(['estado' => 'failed', 'finished_at' => now()]);
    Invoice::factory()->authorized()->for($batch, 'batch')->create();

    $this->actingAs($admin)
        ->get(route('admin.facturacion.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('batchEnProceso.id', $batch->id));
});

it('reanudar un lote sin pendientes lo cierra en vez de dejarlo trabado', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->create(['punto_venta' => 2, 'estado' => 'failed', 'finished_at' => now()]);
    Invoice::factory()->authorized()->for($batch, 'batch')->create(['pto_vta' => 2, 'numero_comprobante' => 5]);

    $this->actingAs($admin)
        ->post(route('admin.facturacion.resume', $batch))
        ->assertRedirect();

    // La cadena queda reducida al cierre y corre sync en tests → el lote termina en terminal.
    expect($batch->fresh()->estado)->toBe('completed')
        ->and($batch->fresh()->finished_at)->not->toBeNull();
});
