<?php

use App\Enums\InvoiceEstado;
use App\Jobs\EmitInvoiceBatch;
use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

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

    Bus::assertDispatched(EmitInvoiceBatch::class, fn (EmitInvoiceBatch $job): bool => $job->batchId === $batch->id && $job->ptoVta === 2);
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

it('descarga un ZIP con los PDFs de las facturas autorizadas', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $batch = InvoiceBatch::factory()->completed()->create();
    $invoice = Invoice::factory()->authorized()->for($batch, 'batch')->create(['pdf_path' => 'invoices/2026-06/factura-c-2-00000101.pdf']);
    Storage::disk('local')->put($invoice->pdf_path, '%PDF-fake');

    $this->actingAs($admin)
        ->get(route('admin.facturacion.download', $batch))
        ->assertOk()
        ->assertDownload("facturas-lote-{$batch->codigo}-{$batch->id}.zip");
});
