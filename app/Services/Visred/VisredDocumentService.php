<?php

namespace App\Services\Visred;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captura de documentos oficiales de la póliza contra Visred (adapter-internal).
 *
 * `POST /v1/documents/ {presale_id, product_id, task_type_id}` → `TaskDTO {result.url}`
 * (SÍNCRONO, URL pre-firmada). Descarga los bytes del PDF y los devuelve como blobs
 * NEUTROS para que el dominio los persista. El `presale_id` es un dato de Visred y
 * NO sale del adapter: entra acá y muere acá.
 *
 * Best-effort: la póliza ya está emitida, así que un fallo de captura NO debe romper
 * la emisión — se loggea y se sigue. Los `task_type_id` a capturar (y su `kind` de
 * dominio) salen de config (`visred.document_task_types`).
 */
class VisredDocumentService
{
    private const DOCUMENTS_PATH = '/v1/documents/';

    public function __construct(private readonly VisredClient $client) {}

    /**
     * @return list<array{kind: string, filename: string, mime: string, contents: string}>
     */
    public function capture(int $presaleId, string $productId): array
    {
        /** @var array<string, string> $taskTypes  task_type_id → kind de dominio */
        $taskTypes = (array) config('visred.document_task_types', []);

        $documents = [];
        foreach ($taskTypes as $taskTypeId => $kind) {
            $blob = $this->fetchOne($presaleId, $productId, (string) $taskTypeId, (string) $kind);
            if ($blob !== null) {
                $documents[] = $blob;
            }
        }

        return $documents;
    }

    /**
     * @return array{kind: string, filename: string, mime: string, contents: string}|null
     */
    private function fetchOne(int $presaleId, string $productId, string $taskTypeId, string $kind): ?array
    {
        try {
            $task = $this->client->post(self::DOCUMENTS_PATH, [
                'presale_id' => $presaleId,
                'product_id' => $productId,
                'task_type_id' => $taskTypeId,
            ]);

            $result = $task['result'] ?? null;
            $url = is_array($result) && is_scalar($result['url'] ?? null) ? (string) $result['url'] : '';

            if ($url === '') {
                Log::warning('[VisredDocument] documento sin result.url', ['task_type_id' => $taskTypeId]);

                return null;
            }

            $response = Http::timeout((int) config('visred.timeout', 30))->get($url);

            if ($response->failed()) {
                Log::warning('[VisredDocument] descarga del result.url falló', [
                    'task_type_id' => $taskTypeId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return [
                'kind' => $kind,
                'filename' => "{$kind}.pdf",
                'mime' => 'application/pdf',
                'contents' => $response->body(),
            ];
        } catch (Throwable $e) {
            Log::warning('[VisredDocument] captura de documento falló (best-effort)', [
                'task_type_id' => $taskTypeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
