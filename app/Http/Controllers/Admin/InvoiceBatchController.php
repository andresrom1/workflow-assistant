<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceEstado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceBatchRequest;
use App\Jobs\CloseInvoiceBatch;
use App\Jobs\EmitInvoice;
use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\Facturacion\Emisor;
use App\Services\InvoicePdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Facturación de comisiones (Facturas C contra AFIP). Solo admin. El usuario arma un lote con
 * los datos comunes + las compañías tildadas y sus importes; se crean las {@see Invoice} en
 * `Pending` y una cadena de {@see EmitInvoice} las emite de a una, cerrando con
 * {@see CloseInvoiceBatch}. El front hace polling hasta que el lote cierra. Los PDF NO se
 * persisten: se generan al vuelo con {@see InvoicePdfService} (descarga individual o ZIP).
 *
 * Un lote que no llegó a cerrar se puede reanudar ({@see self::resume()}); la cadena solo toma los
 * comprobantes que siguen `Pending`, así que reanudar nunca re-emite lo ya autorizado.
 */
class InvoiceBatchController extends Controller
{
    public function __construct(
        private readonly Emisor $emisor,
    ) {}

    public function index(): Response
    {
        // Cualquier lote que no haya cerrado —`processing` o `failed`— sigue a la vista con sus
        // acciones. Si se filtrara solo por `processing`, un lote fallido se volvería invisible y
        // el módulo quedaría bloqueado sin nada en pantalla para destrabarlo.
        $enProceso = InvoiceBatch::query()
            ->where('estado', '!=', 'completed')
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

        $this->despacharCadena($batch);

        return back()->with('flash', ['success' => 'Lote en emisión. Se irá actualizando a medida que AFIP responde.']);
    }

    /**
     * Reanuda un lote que no llegó a cerrar (crash, worker caído, AFIP inaccesible). Vuelve a
     * despachar la cadena solo con lo que sigue `Pending`.
     *
     * Sin comprobantes pendientes NO es un error: la cadena queda reducida al cierre y el lote
     * pasa a terminal. Es a propósito — un lote fallido sin nada por emitir, si no se pudiera
     * cerrar, bloquearía el formulario de lotes nuevos para siempre.
     */
    public function resume(InvoiceBatch $invoiceBatch): RedirectResponse
    {
        $pendientes = $invoiceBatch->invoices()->where('estado', InvoiceEstado::Pending)->count();

        $invoiceBatch->update([
            'estado' => 'processing',
            'finished_at' => null,
            'summary' => null,
        ]);

        $this->despacharCadena($invoiceBatch);

        return back()->with('flash', ['success' => $pendientes === 0
            ? 'El lote no tenía comprobantes pendientes: se cerró.'
            : "Reanudando la emisión de {$pendientes} comprobante(s).",
        ]);
    }

    /**
     * Encadena un job por comprobante pendiente + el cierre del lote.
     *
     * Es una cadena y no un batch de Bus a propósito: `Bus::batch` corre en paralelo y la
     * numeración correlativa de AFIP no lo tolera. El `catch()` es lo que garantiza que el lote
     * llegue a un estado terminal aunque un comprobante agote sus reintentos — si no, queda
     * `processing` para siempre y bloquea el módulo.
     */
    private function despacharCadena(InvoiceBatch $batch): void
    {
        $ptoVta = $batch->punto_venta;

        $jobs = $batch->invoices()
            ->where('estado', InvoiceEstado::Pending)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $invoiceId): EmitInvoice => new EmitInvoice($invoiceId, $ptoVta))
            ->all();

        $batchId = $batch->id;

        Bus::chain([...$jobs, new CloseInvoiceBatch($batchId)])
            ->catch(function () use ($batchId): void {
                CloseInvoiceBatch::dispatch($batchId, 'failed');
            })
            ->dispatch();
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
