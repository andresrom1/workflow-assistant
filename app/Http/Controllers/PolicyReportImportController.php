<?php

namespace App\Http\Controllers;

use App\Enums\IngestaStatus;
use App\Enums\ReporteOrigen;
use App\Models\PolicyReportBatch;
use App\Models\PolicyReportRow;
use App\Services\PolicyReportConfirmacionService;
use App\Services\PolicyReportImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import de reportes de cartera (snapshot de pólizas) subidos al panel. El admin sube un
 * xlsx eligiendo el origen; se estaciona un lote `pendiente` con el diff (dry-run) y, tras
 * revisión, se confirma para materializar la cadena Customer→Risk→Poliza
 * ({@see PolicyReportConfirmacionService}). Modo confirmación por lote: nada se materializa
 * al subir.
 */
class PolicyReportImportController extends Controller
{
    public function __construct(
        private readonly PolicyReportImportService $import,
        private readonly PolicyReportConfirmacionService $confirmacion,
    ) {}

    public function index(): Response
    {
        $pendiente = PolicyReportBatch::query()
            ->where('status', IngestaStatus::Pendiente)
            ->latest('uploaded_at')
            ->first();

        return Inertia::render('ReporteCartera/Index', [
            'origenes' => collect(ReporteOrigen::cases())
                ->map(fn (ReporteOrigen $o): array => ['value' => $o->value, 'label' => $o->label()])
                ->all(),
            'pendiente' => $pendiente === null ? null : $this->presentBatch($pendiente),
            'recientes' => PolicyReportBatch::query()
                ->whereIn('status', [IngestaStatus::Confirmado, IngestaStatus::Descartado])
                ->latest('uploaded_at')
                ->limit(10)
                ->get()
                ->map(fn (PolicyReportBatch $b): array => [
                    'id' => $b->id,
                    'origen' => $b->origen->label(),
                    'original_filename' => $b->original_filename,
                    'status' => $b->status->label(),
                    'summary' => $b->summary,
                    'uploaded_at' => $b->uploaded_at?->toDateTimeString(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'origen' => ['required', Rule::enum(ReporteOrigen::class)],
            'file' => ['required', 'file', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'max:51200'],
        ]);

        $batch = $this->import->stage(
            ReporteOrigen::from($validated['origen']),
            $request->file('file'),
        );

        return back()->with('flash', [
            'success' => $batch->status === IngestaStatus::Pendiente
                ? 'Reporte cargado. Revisá el diff y confirmá.'
                : 'Este reporte ya había sido procesado.',
        ]);
    }

    public function confirm(PolicyReportBatch $policyReportBatch): RedirectResponse
    {
        $this->confirmacion->confirm($policyReportBatch);

        return back()->with('flash', ['success' => 'Lote confirmado y materializado.']);
    }

    public function discard(PolicyReportBatch $policyReportBatch): RedirectResponse
    {
        $this->confirmacion->discard($policyReportBatch);

        return back()->with('flash', ['success' => 'Lote descartado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatch(PolicyReportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'origen' => $batch->origen->label(),
            'original_filename' => $batch->original_filename,
            'uploaded_at' => $batch->uploaded_at?->toDateTimeString(),
            'summary' => $batch->summary,
            'rows' => $batch->rows()
                ->orderByRaw("array_position(array['exception','create','update_estado','noop'], accion)")
                ->orderBy('id')
                ->get()
                ->map(fn (PolicyReportRow $r): array => [
                    'id' => $r->id,
                    'asegurado' => $r->asegurado,
                    'documento' => $r->documento,
                    'numero' => $r->numero,
                    'company' => $r->company,
                    'producto' => $r->producto,
                    'patente' => $r->patente,
                    'estado_origen' => $r->estado_origen,
                    'estado_mapeado' => $r->estado_mapeado?->label(),
                    'vigencia' => $r->vigencia?->toDateString(),
                    'accion' => $r->accion->value,
                    'accion_label' => $r->accion->label(),
                    'nota' => $r->nota,
                ])
                ->all(),
        ];
    }
}
