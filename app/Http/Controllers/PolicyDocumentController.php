<?php

namespace App\Http\Controllers;

use App\Enums\PolicyDocumentKind;
use App\Jobs\DeleteOrphanPhoto;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Services\PolicyDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión manual de documentos de póliza desde el panel.
 *
 * El admin busca una póliza y entra a su gestor de documentos: sube renovaciones/
 * endosos/correcciones (los que NO pasan por Visred), togglea su visibilidad para el
 * cliente y los elimina. La captura automática al emitir vive en otro lado
 * (PolicyDocumentService::storeFromEmission); acá solo entra `admin_upload`.
 */
class PolicyDocumentController extends Controller
{
    public function __construct(
        protected PolicyDocumentService $policyDocumentService,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $perPage = (int) $request->input('per_page', 25);
        $filter = in_array($request->input('filter'), ['with', 'without'], true)
            ? $request->input('filter')
            : 'all';

        $polizas = Poliza::query()
            ->with(['risk.customer', 'latestDocument', 'documents:id,poliza_id,kind'])
            ->withCount('documents')
            ->withCount(['documents as visible_documents_count' => fn ($q) => $q->where('visible_to_client', true)])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('numero', 'ilike', "%{$search}%")
                        ->orWhereHas('risk', fn ($r) => $r->where('metadata->patente', 'ilike', "%{$search}%"))
                        ->orWhereHas('risk.customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->when($filter === 'with', fn ($q) => $q->has('documents'))
            ->when($filter === 'without', fn ($q) => $q->doesntHave('documents'))
            // Documentación-céntrico: las pólizas con carga más reciente arriba; las sin docs al final.
            ->orderByRaw('(select max(captured_at) from policy_documents where policy_documents.poliza_id = polizas.id) desc nulls last')
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Poliza $poliza): array {
                $present = $poliza->documents->map(fn (PolicyDocument $d): PolicyDocumentKind => $d->kind)->unique();
                $expected = PolicyDocumentKind::expectedForActivePolicy();

                return [
                    'id' => $poliza->id,
                    'numero' => $poliza->numero,
                    'company' => $poliza->company,
                    'estado' => $poliza->estado->value,
                    'patente' => $poliza->risk->metadata['patente'] ?? null,
                    'label' => $poliza->risk->label,
                    'cliente' => $poliza->risk->customer?->name,
                    'documents_count' => $poliza->documents_count,
                    'visible_count' => $poliza->visible_documents_count,
                    'doc_presentes' => count(array_filter($expected, fn (PolicyDocumentKind $k): bool => $present->contains($k))),
                    'doc_esperados' => count($expected),
                    'last_kind' => $poliza->latestDocument?->kind->label(),
                    'last_document_at' => $poliza->latestDocument?->captured_at?->toIso8601String(),
                ];
            });

        return Inertia::render('PolicyDocuments/Index', [
            'polizas' => $polizas,
            'filters' => ['search' => $search, 'per_page' => $perPage, 'filter' => $filter],
        ]);
    }

    /**
     * Panel de desviaciones: pólizas activas (vigentes + emitidas) a las que les falta
     * al menos un documento esperado, con el detalle de qué falta. Herramienta de
     * vistazo diario. Excluye las vencidas (de esas no se chasea documentación). El
     * checklist esperado vive en {@see PolicyDocumentKind::expectedForActivePolicy()}.
     */
    public function pendientes(Request $request): Response
    {
        $perPage = (int) $request->input('per_page', 25);
        $expected = PolicyDocumentKind::expectedForActivePolicy();

        $polizas = Poliza::query()
            ->with(['risk.customer', 'documents:id,poliza_id,kind'])
            ->documentacionIncompleta()
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Poliza $poliza) use ($expected): array {
                $present = $poliza->documents->map(fn (PolicyDocument $d): PolicyDocumentKind => $d->kind)->unique();
                $faltantes = array_values(array_filter(
                    $expected,
                    fn (PolicyDocumentKind $k): bool => ! $present->contains($k),
                ));

                return [
                    'id' => $poliza->id,
                    'numero' => $poliza->numero,
                    'company' => $poliza->company,
                    'estado' => $poliza->estado->value,
                    'patente' => $poliza->risk->metadata['patente'] ?? null,
                    'label' => $poliza->risk->label,
                    'cliente' => $poliza->risk->customer?->name,
                    'presentes' => count($expected) - count($faltantes),
                    'esperados' => count($expected),
                    'faltantes' => array_map(
                        fn (PolicyDocumentKind $k): array => ['kind' => $k->value, 'label' => $k->label()],
                        $faltantes,
                    ),
                ];
            });

        return Inertia::render('PolicyDocuments/Pendientes', [
            'polizas' => $polizas,
            'filters' => ['per_page' => $perPage],
        ]);
    }

    public function show(Request $request, Poliza $poliza): Response
    {
        $poliza->load('risk.customer');

        // Preselección del tipo cuando se entra desde un ítem faltante del checklist.
        $preselectKind = PolicyDocumentKind::tryFrom((string) $request->input('kind'))?->value;

        $docs = $poliza->documents()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        // Checklist de completitud: qué documentos esperados de una vigente están y
        // cuáles faltan (guía al operador sobre qué cargar).
        $presentKinds = $docs->map(fn (PolicyDocument $doc): PolicyDocumentKind => $doc->kind)->unique();
        $checklist = array_map(
            fn (PolicyDocumentKind $k): array => [
                'kind' => $k->value,
                'label' => $k->label(),
                'presente' => $presentKinds->contains($k),
            ],
            PolicyDocumentKind::expectedForActivePolicy(),
        );

        $documents = $docs
            ->map(fn (PolicyDocument $doc): array => [
                'id' => $doc->id,
                'kind' => $doc->kind->value,
                'kind_label' => $doc->kind->label(),
                'source' => $doc->source->value,
                'source_label' => $doc->source->label(),
                'original_filename' => $doc->original_filename,
                'label' => $doc->label,
                'visible_to_client' => $doc->visible_to_client,
                'preview_url' => Storage::disk('r2')->temporaryUrl($doc->storage_path, now()->addMinutes(15)),
                'captured_at' => $doc->captured_at?->toIso8601String(),
            ])->all();

        return Inertia::render('PolicyDocuments/Show', [
            'poliza' => [
                'id' => $poliza->id,
                'numero' => $poliza->numero,
                'company' => $poliza->company,
                'coverage' => $poliza->coverage,
                'patente' => $poliza->risk->metadata['patente'] ?? null,
                'label' => $poliza->risk->label,
                'cliente' => $poliza->risk->customer?->name,
                'estado' => $poliza->estado->value,
            ],
            'documents' => $documents,
            'checklist' => $checklist,
            'kinds' => collect(PolicyDocumentKind::cases())
                ->map(fn (PolicyDocumentKind $k): array => ['value' => $k->value, 'label' => $k->label()])
                ->all(),
            'preselectKind' => $preselectKind,
        ]);
    }

    public function store(Request $request, Poliza $poliza): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:51200',
            'kind' => ['required', Rule::enum(PolicyDocumentKind::class)],
            'label' => 'nullable|string|max:120',
        ]);

        // Regla "todo lo de la vigente": ya no hay visibilidad por documento — los de la
        // póliza vigente se entregan todos. Se persiste visible=true por consistencia.
        $this->policyDocumentService->storeAdminUpload(
            $poliza,
            $request->file('file'),
            PolicyDocumentKind::from($validated['kind']),
            true,
            $validated['label'] ?? null,
        );

        return back()->with('flash', ['success' => 'Documento subido a la póliza.']);
    }

    public function destroy(PolicyDocument $policyDocument): RedirectResponse
    {
        DeleteOrphanPhoto::dispatch($policyDocument->storage_path);
        $policyDocument->delete();

        return back()->with('flash', ['success' => 'Documento eliminado.']);
    }
}
