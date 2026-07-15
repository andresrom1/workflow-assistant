<?php

use App\Enums\IngestaStatus;
use App\Enums\PolizaEstado;
use App\Enums\ReporteOrigen;
use App\Enums\ReporteRowAccion;
use App\Models\Customer;
use App\Models\PolicyReportBatch;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use App\Services\PolicyReportConfirmacionService;
use App\Services\PolicyReportImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

const REPORT_HEADERS = [
    'ID', 'Nro de Póliza', 'Productor', 'Código Productor', 'Asegurado', 'CUIT',
    'Teléfono', 'Email', 'Patente', 'Compañía', 'Producto', 'Ramo', 'Estado',
    'Estado Póliza', 'Inicio Vigencia', 'Fin Vigencia', 'Premio', 'Costo',
];

/**
 * Construye un xlsx del Portal Visred en un temp file y lo envuelve en UploadedFile.
 *
 * @param  list<array<string, string>>  $rows  cada fila por header (los faltantes van vacíos)
 */
function reportXlsx(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(REPORT_HEADERS));

    foreach ($rows as $row) {
        $cells = array_map(fn (string $h): string => $row[$h] ?? '', REPORT_HEADERS);
        $writer->addRow(Row::fromValues($cells));
    }

    $writer->close();

    return new UploadedFile($path, 'Listado.xlsx', null, null, true);
}

function importService(): PolicyReportImportService
{
    return app(PolicyReportImportService::class);
}

function stageReport(array $rows): PolicyReportBatch
{
    return importService()->stage(ReporteOrigen::PortalVisred, reportXlsx($rows));
}

$autoVigente = [
    'Nro de Póliza' => '01-03-01-32032109',
    'Asegurado' => 'SILVIO ALEJANDRO CEFERIN',
    'CUIT' => '24214222',
    'Patente' => '101AF089',
    'Compañía' => 'San Cristobal',
    'Producto' => 'Auto',
    'Ramo' => 'Patrimoniales',
    'Estado' => 'Vigente',
    'Fin Vigencia' => '08/07/2026',
];

it('estaciona el lote con el diff sin materializar nada', function () use ($autoVigente): void {
    $batch = stageReport([
        $autoVigente,
        ['Nro de Póliza' => '999', 'Asegurado' => 'Juan Vida', 'CUIT' => '30111222', 'Compañía' => 'Sancor', 'Producto' => 'Vida Individual', 'Estado' => 'Vigente'], // sin patente → exception
    ]);

    expect($batch->status)->toBe(IngestaStatus::Pendiente)
        ->and($batch->summary['create'])->toBe(1)
        ->and($batch->summary['exception'])->toBe(1)
        ->and($batch->summary['total'])->toBe(2)
        ->and($batch->summary['nuevos_clientes'])->toBe(1);

    // Nada de dominio se tocó al estacionar.
    expect(Poliza::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
});

it('materializa la cadena Customer→Risk→Poliza al confirmar', function () use ($autoVigente): void {
    $batch = stageReport([$autoVigente]);

    app(PolicyReportConfirmacionService::class)->confirm($batch);

    $customer = Customer::where('dni', '24214222')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('SILVIO ALEJANDRO CEFERIN');

    $risk = Risk::where('customer_id', $customer->id)->first();
    expect($risk->asset->metadata['patente'])->toBe('101AF089');

    $poliza = Poliza::where('numero', '01-03-01-32032109')->where('company', 'San Cristobal')->first();
    expect($poliza)->not->toBeNull()
        ->and($poliza->estado)->toBe(PolizaEstado::Vigente)
        ->and($poliza->risk_id)->toBe($risk->id)
        ->and($poliza->vigencia->toDateString())->toBe('2026-07-08')
        ->and($poliza->metadata['origen_reporte'])->toBe('portal_visred')
        ->and($poliza->last_synced_at)->not->toBeNull();

    expect($batch->refresh()->status)->toBe(IngestaStatus::Confirmado);
});

it('mapea los estados del reporte (No-Vigente→NoVigente, En-Proceso→EnProceso, Programada→Programada)', function (): void {
    $batch = stageReport([
        ['Nro de Póliza' => 'A1', 'Asegurado' => 'A', 'CUIT' => '1', 'Patente' => 'AAA111', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'No-Vigente'],
        ['Nro de Póliza' => 'A2', 'Asegurado' => 'B', 'CUIT' => '2', 'Patente' => 'BBB222', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'En-Proceso'],
        ['Nro de Póliza' => 'A3', 'Asegurado' => 'C', 'CUIT' => '3', 'Patente' => 'CCC333', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'Programada'],
        ['Nro de Póliza' => 'A4', 'Asegurado' => 'D', 'CUIT' => '4', 'Patente' => 'DDD444', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'Anulada'],
    ]);

    app(PolicyReportConfirmacionService::class)->confirm($batch);

    expect(Poliza::where('numero', 'A1')->value('estado'))->toBe(PolizaEstado::NoVigente)
        ->and(Poliza::where('numero', 'A2')->value('estado'))->toBe(PolizaEstado::EnProceso)
        ->and(Poliza::where('numero', 'A3')->value('estado'))->toBe(PolizaEstado::Programada)
        ->and(Poliza::where('numero', 'A4')->value('estado'))->toBe(PolizaEstado::Anulada);
});

it('es idempotente por hash del archivo: re-subir devuelve el mismo lote', function () use ($autoVigente): void {
    $file = reportXlsx([$autoVigente]);
    $hash = hash_file('sha256', $file->getPathname());

    $first = importService()->stage(ReporteOrigen::PortalVisred, $file);
    // Mismo contenido → mismo hash → mismo lote.
    $second = importService()->stage(ReporteOrigen::PortalVisred, new UploadedFile($file->getPathname(), 'Listado.xlsx', null, null, true));

    expect($second->id)->toBe($first->id)
        ->and(PolicyReportBatch::count())->toBe(1)
        ->and($first->hash_sha256)->toBe($hash);
});

it('reutiliza un cliente existente por DNI en vez de duplicarlo', function () use ($autoVigente): void {
    $existing = Customer::factory()->create(['dni' => '24214222']);

    $batch = stageReport([$autoVigente]);
    app(PolicyReportConfirmacionService::class)->confirm($batch);

    expect(Customer::where('dni', '24214222')->count())->toBe(1)
        ->and(Poliza::first()->risk->customer_id)->toBe($existing->id);
});

it('actualiza el estado de una póliza existente (update_estado)', function (): void {
    // Primer reporte: alta vigente.
    $row = ['Nro de Póliza' => 'P1', 'Asegurado' => 'E', 'CUIT' => '55', 'Patente' => 'EEE555', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'Vigente', 'Fin Vigencia' => '01/01/2027'];
    app(PolicyReportConfirmacionService::class)->confirm(stageReport([$row]));

    // Segundo reporte: la misma póliza ahora No-Vigente.
    $row['Estado'] = 'No-Vigente';
    $batch = stageReport([$row]);

    expect($batch->summary['update_estado'])->toBe(1)
        ->and($batch->rows()->first()->accion)->toBe(ReporteRowAccion::UpdateEstado);

    app(PolicyReportConfirmacionService::class)->confirm($batch);

    expect(Poliza::where('numero', 'P1')->count())->toBe(1)
        ->and(Poliza::where('numero', 'P1')->value('estado'))->toBe(PolizaEstado::NoVigente);
});

it('marca exception cuando una fila sin patente sería un alta nueva', function (): void {
    $batch = stageReport([
        ['Nro de Póliza' => 'V1', 'Asegurado' => 'Vida', 'CUIT' => '77', 'Compañía' => 'X', 'Producto' => 'Vida Individual', 'Estado' => 'Vigente'],
    ]);

    $row = $batch->rows()->first();
    expect($row->accion)->toBe(ReporteRowAccion::Exception)
        ->and($row->nota)->toContain('Sin patente');

    app(PolicyReportConfirmacionService::class)->confirm($batch);
    expect(Poliza::count())->toBe(0);
});

it('respeta la invariante: report Vigente cuando el Risk ya tiene una vigente → exception', function (): void {
    // Alta vigente inicial.
    app(PolicyReportConfirmacionService::class)->confirm(stageReport([
        ['Nro de Póliza' => 'I1', 'Asegurado' => 'F', 'CUIT' => '88', 'Patente' => 'FFF888', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'Vigente'],
    ]));

    // Otra póliza distinta, misma patente, también vigente → rompería la invariante.
    $batch = stageReport([
        ['Nro de Póliza' => 'I2', 'Asegurado' => 'F', 'CUIT' => '88', 'Patente' => 'FFF888', 'Compañía' => 'X', 'Producto' => 'Auto', 'Estado' => 'Vigente'],
    ]);

    $row = $batch->rows()->first();
    expect($row->accion)->toBe(ReporteRowAccion::Exception)
        ->and($row->nota)->toContain('vigente');

    app(PolicyReportConfirmacionService::class)->confirm($batch);
    expect(Poliza::where('numero', 'I2')->exists())->toBeFalse();
});

it('descarta el lote sin materializar nada', function () use ($autoVigente): void {
    $batch = stageReport([$autoVigente]);

    app(PolicyReportConfirmacionService::class)->discard($batch);

    expect($batch->refresh()->status)->toBe(IngestaStatus::Descartado)
        ->and(Poliza::count())->toBe(0);
});

// ─── Controller ───────────────────────────────────────────────────────────────

it('sube un reporte desde el controller y estaciona el lote', function () use ($autoVigente): void {
    $this->actingAs(User::factory()->create())
        ->post(route('reporte-cartera.store'), [
            'origen' => ReporteOrigen::PortalVisred->value,
            'file' => reportXlsx([$autoVigente]),
        ])
        ->assertRedirect();

    expect(PolicyReportBatch::where('status', IngestaStatus::Pendiente)->count())->toBe(1);
});

it('confirma el lote desde el controller', function () use ($autoVigente): void {
    $batch = stageReport([$autoVigente]);

    $this->actingAs(User::factory()->create())
        ->post(route('reporte-cartera.confirm', $batch))
        ->assertRedirect();

    expect($batch->refresh()->status)->toBe(IngestaStatus::Confirmado)
        ->and(Poliza::where('numero', '01-03-01-32032109')->exists())->toBeTrue();
});
