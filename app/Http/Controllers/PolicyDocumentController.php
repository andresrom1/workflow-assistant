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

        $polizas = Poliza::query()
            ->with('risk.customer')
            ->withCount('documents')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('numero', 'ilike', "%{$search}%")
                        ->orWhereHas('risk', fn ($r) => $r->where('metadata->patente', 'ilike', "%{$search}%"))
                        ->orWhereHas('risk.customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Poliza $poliza): array => [
                'id' => $poliza->id,
                'numero' => $poliza->numero,
                'company' => $poliza->company,
                'patente' => $poliza->risk->metadata['patente'] ?? null,
                'label' => $poliza->risk->label,
                'cliente' => $poliza->risk->customer?->name,
                'estado' => $poliza->estado->value,
                'documents_count' => $poliza->documents_count,
            ]);

        return Inertia::render('PolicyDocuments/Index', [
            'polizas' => $polizas,
            'filters' => ['search' => $search, 'per_page' => $perPage],
        ]);
    }

    public function show(Poliza $poliza): Response
    {
        $poliza->load('risk.customer');

        $documents = $poliza->documents()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
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
            'kinds' => collect(PolicyDocumentKind::cases())
                ->map(fn (PolicyDocumentKind $k): array => ['value' => $k->value, 'label' => $k->label()])
                ->all(),
        ]);
    }

    public function store(Request $request, Poliza $poliza): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:51200',
            'kind' => ['required', Rule::enum(PolicyDocumentKind::class)],
            'label' => 'nullable|string|max:120',
            'visible_to_client' => 'boolean',
        ]);

        $this->policyDocumentService->storeAdminUpload(
            $poliza,
            $request->file('file'),
            PolicyDocumentKind::from($validated['kind']),
            (bool) ($validated['visible_to_client'] ?? false),
            $validated['label'] ?? null,
        );

        return back()->with('flash', ['success' => 'Documento subido a la póliza.']);
    }

    public function toggleVisibility(PolicyDocument $policyDocument): RedirectResponse
    {
        $policyDocument->update(['visible_to_client' => ! $policyDocument->visible_to_client]);

        $message = $policyDocument->visible_to_client
            ? 'Documento visible para el cliente.'
            : 'Documento oculto para el cliente.';

        return back()->with('flash', ['success' => $message]);
    }

    public function destroy(PolicyDocument $policyDocument): RedirectResponse
    {
        DeleteOrphanPhoto::dispatch($policyDocument->storage_path);
        $policyDocument->delete();

        return back()->with('flash', ['success' => 'Documento eliminado.']);
    }
}
