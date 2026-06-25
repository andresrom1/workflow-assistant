<?php

namespace App\Http\Controllers;

use App\Enums\IngestaStatus;
use App\Enums\PolizaEstado;
use App\Models\IngestedDocument;
use App\Services\IngestaConfirmacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cola de Pendientes del ingestor local: revisa lo que subió el script Python y, con
 * confirmación humana, materializa la cadena Customer→Risk→Poliza→PolicyDocument
 * ({@see IngestaConfirmacionService}). Documentos del mismo contrato se agrupan por
 * `numero_poliza` (fallback `patente`) para revisarlos juntos. Ver doc v3/04 §4-§5.
 *
 * Es distinta de `/documentacion-pendiente` (checklist de completitud sobre pólizas ya
 * existentes): acá lo que está pendiente es el **alta**, no un documento faltante.
 */
class IngestaPendientesController extends Controller
{
    public function __construct(
        private readonly IngestaConfirmacionService $confirmacion,
    ) {}

    public function index(): Response
    {
        $pendientes = IngestedDocument::query()
            ->where('status', IngestaStatus::Pendiente)
            ->orderByDesc('detectado_en')
            ->orderByDesc('id')
            ->get();

        // Agrupar por contrato: numero_poliza si lo hay, sino patente, sino el id (suelto).
        $grupos = $pendientes
            ->groupBy(fn (IngestedDocument $d): string => match (true) {
                $d->numero_poliza !== null => "num:{$d->numero_poliza}",
                $d->patente !== null => "pat:{$d->patente}",
                default => "id:{$d->id}",
            })
            ->map(function ($docs): array {
                /** @var IngestedDocument $head */
                $head = $docs->first();
                $sugerida = $this->confirmacion->sugerirContratoAnterior($head);

                return [
                    'numero_poliza' => $head->numero_poliza,
                    'compania' => $head->compania,
                    'patente' => $head->patente,
                    'contrato_anterior_sugerido' => $sugerida === null ? null : [
                        'id' => $sugerida->id,
                        'numero' => $sugerida->numero,
                        'vigencia' => $sugerida->vigencia?->toDateString(),
                    ],
                    'documentos' => $docs->map(fn (IngestedDocument $d): array => [
                        'id' => $d->id,
                        'kind' => $d->kind->value,
                        'kind_label' => $d->kind->label(),
                        'compania' => $d->compania,
                        'numero_poliza' => $d->numero_poliza,
                        'documento_numero' => $d->documento_numero,
                        'patente' => $d->patente,
                        'tomador' => trim((string) data_get($d->payload, 'tomador.first_name').' '.(string) data_get($d->payload, 'tomador.last_name')) ?: data_get($d->payload, 'tomador.razon_social'),
                        'vigencia_desde' => data_get($d->payload, 'fechas.vigencia_desde'),
                        'vigencia_hasta' => data_get($d->payload, 'fechas.vigencia_hasta'),
                        'campos_no_extraidos' => $d->campos_no_extraidos ?? [],
                        'original_filename' => $d->original_filename,
                        'preview_url' => Storage::disk('r2')->temporaryUrl($d->storage_path, now()->addMinutes(15)),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('PolicyDocuments/PendientesIngesta', [
            'grupos' => $grupos,
            'estados' => collect(PolizaEstado::cases())
                ->map(fn (PolizaEstado $e): array => ['value' => $e->value, 'label' => ucfirst($e->value)])
                ->all(),
        ]);
    }

    public function confirm(Request $request, IngestedDocument $ingestedDocument): RedirectResponse
    {
        $overrides = $request->validate([
            'documento_numero' => ['nullable', 'string', 'max:20'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'numero_poliza' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:120'],
            'patente' => ['nullable', 'string', 'max:12'],
            'estado' => ['nullable', Rule::enum(PolizaEstado::class)],
            'contrato_anterior_id' => ['nullable', 'integer', 'exists:polizas,id'],
        ]);

        $this->confirmacion->confirm($ingestedDocument, $overrides);

        return back()->with('flash', ['success' => 'Alta confirmada y materializada.']);
    }

    public function discard(IngestedDocument $ingestedDocument): RedirectResponse
    {
        $this->confirmacion->discard($ingestedDocument);

        return back()->with('flash', ['success' => 'Documento descartado.']);
    }
}
