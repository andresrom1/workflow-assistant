<?php

namespace App\Services;

use App\Models\PolicyDocument;
use App\Models\Poliza;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    /**
     * @param  list<array{kind: string, filename?: string, mime?: string, contents: string}>  $documents
     */
    public function storeFromEmission(Poliza $poliza, array $documents): void
    {
        foreach ($documents as $document) {
            $this->storeOne($poliza, $document, 'visred_emission');
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function storeOne(Poliza $poliza, array $document, string $source): void
    {
        $kind = is_scalar($document['kind'] ?? null) ? (string) $document['kind'] : '';
        $contents = $document['contents'] ?? null;

        if ($kind === '' || ! is_string($contents) || $contents === '') {
            return;
        }

        try {
            $path = "policy-documents/{$poliza->id}/{$kind}.pdf";
            Storage::disk('r2')->put($path, $contents);

            PolicyDocument::updateOrCreate(
                ['poliza_id' => $poliza->id, 'kind' => $kind, 'source' => $source],
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
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
