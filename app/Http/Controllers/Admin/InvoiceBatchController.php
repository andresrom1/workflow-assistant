<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceEstado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceBatchRequest;
use App\Jobs\EmitInvoiceBatch;
use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\Facturacion\Emisor;
use App\Services\InvoicePdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Facturación de comisiones (Facturas C contra AFIP). Solo admin. El usuario arma un lote con
 * los datos comunes + las compañías tildadas y sus importes; se crean las {@see Invoice} en
 * `Pending` y un job las emite una por una ({@see EmitInvoiceBatch}). El front hace polling
 * hasta que el lote cierra. Los PDF NO se persisten: se generan al vuelo con
 * {@see InvoicePdfService} (descarga individual o ZIP del lote).
 */
class InvoiceBatchController extends Controller
{
    public function __construct(
        private readonly Emisor $emisor,
    ) {}

    public function index(): Response
    {
        $enProceso = InvoiceBatch::query()
            ->where('estado', 'processing')
            ->latest('id')
            ->first();

        return Inertia::render('Facturacion/Index', [
            'companies' => BillingCompany::query()
                ->where('activo', true)
                ->orderBy('razon_social')
                ->get(['id', 'razon_social', 'cuit', 'condicion_iva']),
            'puntoVenta' => $this->emisor->puntoVenta(),
            'batchEnProceso' => $enProceso === null ? null : $this->presentBatch($enProceso),
            'recientes' => InvoiceBatch::query()
                ->where('estado', 'completed')
                ->latest('finished_at')
                ->limit(10)
                ->get()
                ->map(fn (InvoiceBatch $b): array => [
                    'id' => $b->id,
                    'codigo' => $b->codigo,
                    'concepto' => $b->concepto,
                    'summary' => $b->summary,
                    'finished_at' => $b->finished_at?->toDateTimeString(),
                ])
                ->all(),
            // Mapa codigo → [billing_company_id] ya facturadas, para el aviso de duplicado.
            'facturadasPorCodigo' => Invoice::query()
                ->where('estado', InvoiceEstado::Authorized)
                ->get(['codigo', 'billing_company_id'])
                ->groupBy('codigo')
                ->map(fn ($rows) => $rows->pluck('billing_company_id')->unique()->values())
                ->all(),
        ]);
    }

    public function store(StoreInvoiceBatchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $hoy = now()->toDateString();
        $ptoVta = $this->emisor->puntoVenta();

        $batch = DB::transaction(function () use ($validated, $hoy, $ptoVta, $request): InvoiceBatch {
            $batch = InvoiceBatch::create([
                'codigo' => $validated['codigo'],
                'concepto' => $validated['concepto'],
                'punto_venta' => $ptoVta,
                'fecha_comprobante' => $hoy,
                'fecha_servicio_desde' => $validated['fecha_servicio_desde'],
                'fecha_servicio_hasta' => $validated['fecha_servicio_hasta'],
                'fecha_vto_pago' => $validated['fecha_vto_pago'],
                'estado' => 'processing',
                'user_id' => $request->user()?->id,
            ]);

            $companies = BillingCompany::whereIn('id', collect($validated['empresas'])->pluck('id'))->get()->keyBy('id');

            foreach ($validated['empresas'] as $empresa) {
                $company = $companies[$empresa['id']];

                $batch->invoices()->create([
                    'billing_company_id' => $company->id,
                    'importe' => $empresa['importe'],
                    'pto_vta' => $ptoVta,
                    'tipo_comprobante' => (int) config('afip.tipo_comprobante'),
                    'codigo' => $batch->codigo,
                    'fecha_comprobante' => $hoy,
                    'fecha_servicio_desde' => $batch->fecha_servicio_desde,
                    'fecha_servicio_hasta' => $batch->fecha_servicio_hasta,
                    'fecha_vto_pago' => $batch->fecha_vto_pago,
                    'receptor_razon_social' => $company->razon_social,
                    'receptor_cuit' => $company->cuit,
                    'receptor_condicion_iva' => $company->condicion_iva,
                    'receptor_domicilio' => $company->domicilio,
                    'estado' => InvoiceEstado::Pending,
                ]);
            }

            return $batch;
        });

        EmitInvoiceBatch::dispatch($batch->id, $ptoVta);

        return back()->with('flash', ['success' => 'Lote en emisión. Se irá actualizando a medida que AFIP responde.']);
    }

    public function show(InvoiceBatch $invoiceBatch): Response
    {
        return Inertia::render('Facturacion/BatchShow', [
            'batch' => $this->presentBatch($invoiceBatch),
        ]);
    }

    /**
     * PDF de una factura autorizada, generado al vuelo (no persistido).
     */
    public function downloadInvoice(Invoice $invoice, InvoicePdfService $pdf): HttpResponse
    {
        abort_unless($invoice->estado === InvoiceEstado::Authorized, 404, 'La factura no está autorizada.');

        return response($pdf->generar($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
        ]);
    }

    /**
     * ZIP con los PDF de las facturas autorizadas del lote, generados al vuelo.
     */
    public function download(InvoiceBatch $invoiceBatch, InvoicePdfService $pdf): BinaryFileResponse
    {
        $autorizadas = $invoiceBatch->invoices()
            ->where('estado', InvoiceEstado::Authorized)
            ->get();

        abort_if($autorizadas->isEmpty(), 404, 'No hay comprobantes autorizados en este lote.');

        $zipPath = tempnam(sys_get_temp_dir(), 'lote_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($autorizadas as $invoice) {
            $zip->addFromString($pdf->filename($invoice), $pdf->generar($invoice));
        }

        $zip->close();

        $filename = "facturas-lote-{$invoiceBatch->codigo}-{$invoiceBatch->id}.zip";

        return response()->download($zipPath, $filename)->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatch(InvoiceBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'codigo' => $batch->codigo,
            'concepto' => $batch->concepto,
            'punto_venta' => $batch->punto_venta,
            'fecha_comprobante' => $batch->fecha_comprobante->toDateString(),
            'fecha_servicio_desde' => $batch->fecha_servicio_desde->toDateString(),
            'fecha_servicio_hasta' => $batch->fecha_servicio_hasta->toDateString(),
            'fecha_vto_pago' => $batch->fecha_vto_pago->toDateString(),
            'estado' => $batch->estado,
            'finished_at' => $batch->finished_at?->toDateTimeString(),
            'summary' => $batch->summary,
            'invoices' => $batch->invoices()
                ->with('company:id,razon_social')
                ->orderBy('id')
                ->get()
                ->map(fn (Invoice $i): array => [
                    'id' => $i->id,
                    'company' => $i->company?->razon_social,
                    'importe' => $i->importe,
                    'numero_comprobante' => $i->numero_comprobante,
                    'cae' => $i->cae,
                    'cae_vencimiento' => $i->cae_vencimiento?->toDateString(),
                    'estado' => $i->estado->value,
                    'estado_label' => $i->estado->label(),
                    'observaciones' => $i->observaciones,
                ])
                ->all(),
        ];
    }
}
