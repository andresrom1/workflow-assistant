<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractCoverageDocumentText;
use App\Models\CoverageDocument;
use App\Services\ChunkAndEmbedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CoverageDocumentController extends Controller
{
    public function __construct(
        protected ChunkAndEmbedService $chunkAndEmbedService,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->input('search', '');
        $perPage = $request->input('per_page', 25);
        $sort = $request->input('sort');
        $direction = strtolower((string) $request->input('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $query = CoverageDocument::query()
            ->withCount('chunks')
            ->when($search, fn ($q, $s) => $q->where('company_name', 'like', "%{$s}%")
                ->orWhere('original_filename', 'like', "%{$s}%"));

        $allowedSorts = [
            'company_name', 'company_slug', 'document_type', 'original_filename',
            'extraction_status', 'extraction_mode', 'is_active', 'version',
            'updated_at', 'created_at', 'chunks_count',
        ];
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderBy('company_slug')
                ->orderByDesc('is_active')
                ->orderByDesc('updated_at');
        }

        $documents = $query->paginate($perPage)
            ->through(fn (CoverageDocument $doc): array => [
                'id' => $doc->id,
                'company_name' => $doc->company_name,
                'company_slug' => $doc->company_slug,
                'document_type' => $doc->document_type,
                'original_filename' => $doc->original_filename,
                'extraction_status' => $doc->extraction_status,
                'extraction_mode' => $doc->extraction_mode,
                'is_active' => $doc->is_active,
                'version' => $doc->version,
                'chunks_count' => $doc->chunks_count,
                'updated_at' => $doc->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('CoverageDocuments/Index', [
            'documents' => $documents,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'document_type' => 'required|string|in:insert,asistencia,manual,general',
            'file' => 'required|file|mimes:pdf|max:51200',
            'version' => 'nullable|string|max:50',
            'extraction_mode' => 'required|string|in:ai,manual',
            'extraction_provider' => 'nullable|string|in:openai,anthropic,gemini',
        ]);

        $slug = Str::slug($validated['company_name']);
        $file = $request->file('file');
        $path = $file->storeAs("coverage-documents/{$slug}", $file->getClientOriginalName(), 'local');

        // Auto-deprecate old doc of same company+type
        CoverageDocument::where('company_slug', $slug)
            ->where('document_type', $validated['document_type'])
            ->where('is_active', true)
            ->each(fn (CoverageDocument $doc) => $doc->deprecate());

        $doc = CoverageDocument::create([
            'company_slug' => $slug,
            'company_name' => $validated['company_name'],
            'document_type' => $validated['document_type'],
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'storage_disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'version' => $validated['version'],
            'extraction_mode' => $validated['extraction_mode'],
            'extraction_provider' => $validated['extraction_provider'] ?? 'openai',
            'extraction_status' => $validated['extraction_mode'] === 'manual' ? 'manual' : 'pending',
        ]);

        if ($validated['extraction_mode'] === 'ai') {
            ExtractCoverageDocumentText::dispatch($doc);
        }

        $message = $validated['extraction_mode'] === 'ai'
            ? 'Documento subido. Extraccion en progreso.'
            : 'Documento subido. Ingresa el contenido manualmente.';

        return redirect()->route('coverage-documents.show', $doc)
            ->with('flash', ['success' => $message]);
    }

    public function show(CoverageDocument $coverageDocument): Response
    {
        $coverageDocument->loadCount('chunks');

        return Inertia::render('CoverageDocuments/Show', [
            'document' => [
                'id' => $coverageDocument->id,
                'company_name' => $coverageDocument->company_name,
                'company_slug' => $coverageDocument->company_slug,
                'document_type' => $coverageDocument->document_type,
                'original_filename' => $coverageDocument->original_filename,
                'storage_path' => $coverageDocument->storage_path,
                'mime_type' => $coverageDocument->mime_type,
                'extracted_content' => $coverageDocument->extracted_content,
                'extraction_status' => $coverageDocument->extraction_status,
                'extraction_mode' => $coverageDocument->extraction_mode,
                'extraction_provider' => $coverageDocument->extraction_provider,
                'is_active' => $coverageDocument->is_active,
                'version' => $coverageDocument->version,
                'chunks_count' => $coverageDocument->chunks_count,
                'deprecated_at' => $coverageDocument->deprecated_at,
                'created_at' => $coverageDocument->created_at?->toIso8601String(),
                'updated_at' => $coverageDocument->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, CoverageDocument $coverageDocument): RedirectResponse
    {
        $request->validate([
            'extracted_content' => 'required|string',
        ]);

        $coverageDocument->update([
            'extracted_content' => $request->input('extracted_content'),
            'extraction_status' => 'completed',
        ]);

        $chunksCount = $this->chunkAndEmbedService->execute($coverageDocument);

        return back()->with('flash', ['success' => "Texto actualizado. {$chunksCount} chunks generados."]);
    }

    public function destroy(CoverageDocument $coverageDocument): RedirectResponse
    {
        $coverageDocument->deprecate();

        return redirect()->route('coverage-documents.index')
            ->with('flash', ['success' => 'Documento deprecado.']);
    }
}
