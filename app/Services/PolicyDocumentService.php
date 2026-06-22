<?php

namespace App\Services;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Persiste en R2 los documentos oficiales de una póliza y los registra en cartera.
 *
 * Agnóstico de canal/proveedor: recibe blobs NEUTROS (`{kind, filename, mime,
 * contents}`) — no conoce Visred ni `presale_id`. La fuente `visred_emission` se
 * captura al emitir (snapshot, doc 10 §5); `admin_upload` es la carga manual
 * post-emisión (deuda admin panel). Best-effort: la póliza ya está emitida, así que
 * un fallo de persistencia NO debe romper la emisión.
 */
class PolicyDocumentService
{
    public function __construct(
        private readonly PolicyDocumentNotifier $notifier,
    ) {}

    /**
     * @param  list<array{kind: string, filename?: string, mime?: string, contents: string}>  $documents
     */
    public function storeFromEmission(Poliza $poliza, array $documents): void
    {
        foreach ($documents as $document) {
            $this->storeOne($poliza, $document);
        }
    }

    /**
     * Carga manual desde el admin (post-emisión: renovaciones/endosos/correcciones).
     *
     * Siempre `create()`: los documentos se acumulan al contrato, nunca se reemplazan,
     * así que conviven varios del mismo `kind`. El `uuid` del path evita colisiones.
     * No es best-effort: si la persistencia en R2 falla, lanza para que el admin lo vea.
     */
    public function storeAdminUpload(
        Poliza $poliza,
        UploadedFile $file,
        PolicyDocumentKind $kind,
        bool $visibleToClient,
        ?string $label = null,
    ): PolicyDocument {
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'pdf';
        $path = "policy-documents/{$poliza->id}/{$kind->value}-".Str::uuid()->toString().".{$extension}";

        $stored = Storage::disk('r2')->put($path, $file->get());

        if ($stored === false) {
            throw new RuntimeException("No se pudo guardar el documento en R2 (póliza {$poliza->id}).");
        }

        $document = PolicyDocument::create([
            'poliza_id' => $poliza->id,
            'kind' => $kind,
            'storage_path' => $path,
            'storage_url' => Storage::disk('r2')->url($path),
            'original_filename' => $file->getClientOriginalName(),
            'label' => $label,
            'source' => PolicyDocumentSource::AdminUpload,
            'visible_to_client' => $visibleToClient,
            'captured_at' => now(),
        ]);

        // Best-effort: avisar al asegurado que hay documentación nueva (si la póliza está
        // vigente y el titular tiene la app). Un fallo del aviso no debe romper la carga.
        try {
            $this->notifier->notifyNewDocument($poliza, $kind);
        } catch (Throwable $e) {
            Log::warning('PolicyDocumentService: no se pudo encolar el aviso de documento nuevo', [
                'poliza_id' => $poliza->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function storeOne(Poliza $poliza, array $document): void
    {
        $kind = is_scalar($document['kind'] ?? null)
            ? PolicyDocumentKind::tryFrom((string) $document['kind'])
            : null;
        $contents = $document['contents'] ?? null;

        if ($kind === null || ! is_string($contents) || $contents === '') {
            return;
        }

        try {
            $path = "policy-documents/{$poliza->id}/{$kind->value}.pdf";
            Storage::disk('r2')->put($path, $contents);

            PolicyDocument::updateOrCreate(
                ['poliza_id' => $poliza->id, 'kind' => $kind, 'source' => PolicyDocumentSource::VisredEmission],
                [
                    'storage_path' => $path,
                    'storage_url' => Storage::disk('r2')->url($path),
                    'visible_to_client' => true,
                    'captured_at' => now(),
                ],
            );
        } catch (Throwable $e) {
            Log::error('PolicyDocumentService: falló la persistencia de un documento (póliza ya emitida)', [
                'poliza_id' => $poliza->id,
                'kind' => $kind->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
