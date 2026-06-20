<?php

namespace App\Services\Visred;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captura de documentos oficiales de la póliza contra Visred (adapter-internal).
 *
 * `POST /v1/documents/ {presale_id, product_id, task_type_id}` dispara la generación
 * del PDF del lado de la compañía y es ASÍNCRONO: recién emitida la póliza el documento
 * todavía se está generando, así que `result.url` viene vacío. Cada llamada hace UN solo
 * intento (no poll-ea internamente): si la URL pre-firmada ya está, descarga los bytes y
 * los devuelve como blobs NEUTROS; si no, marca el documento como pendiente. La CADENCIA
 * de reintento (1 intento por minuto, hasta ~10 min) la maneja el job
 * `CapturePendingPolicyDocuments` re-encolándose con backoff — no se bloquea un worker
 * esperando. El `presale_id` es un dato de Visred y NO sale del adapter: entra acá y muere acá.
 *
 * Best-effort: la póliza ya está emitida, así que un fallo de captura NO debe romper la
 * emisión — se loggea y se sigue. Lo que no se capture inline lo reintenta el job (ver
 * {@see capture()}). Los `task_type_id` a capturar (y su `kind` de dominio) salen de
 * config (`visred.document_task_types`).
 */
class VisredDocumentService
{
    private const DOCUMENTS_PATH = '/v1/documents/';

    public function __construct(private readonly VisredClient $client) {}

    /**
     * Captura los documentos del catálogo (`visred.document_task_types`). Devuelve los
     * que estuvieron listos como blobs neutros + la lista de `kind` que siguen
     * generándose (para reintento diferido por el dominio).
     *
     * @return array{
     *     documents: list<array{kind: string, filename: string, mime: string, contents: string}>,
     *     pending: list<string>
     * }
     */
    public function capture(int $presaleId, string $productId): array
    {
        /** @var array<string, string> $taskTypes  task_type_id → kind de dominio */
        $taskTypes = (array) config('visred.document_task_types', []);

        $documents = [];
        $pending = [];

        foreach ($taskTypes as $taskTypeId => $kind) {
            $blob = $this->fetchOne($presaleId, $productId, (string) $taskTypeId, (string) $kind);
            if ($blob !== null) {
                $documents[] = $blob;
            } else {
                $pending[] = (string) $kind;
            }
        }

        return ['documents' => $documents, 'pending' => $pending];
    }

    /**
     * Captura DIFERIDA: re-pide solo los `kind` de dominio indicados (reintento del job,
     * fuera del request de emisión). Mapea cada `kind` a su `task_type_id` de Visred
     * (inversa del catálogo de config) y devuelve los que ya estén listos.
     *
     * @param  list<string>  $kinds  `kind` de dominio (PolicyDocumentKind) pendientes.
     * @return list<array{kind: string, filename: string, mime: string, contents: string}>
     */
    public function captureKinds(int $presaleId, string $productId, array $kinds): array
    {
        /** @var array<string, string> $taskTypeByKind  kind → task_type_id */
        $taskTypeByKind = array_flip((array) config('visred.document_task_types', []));

        $documents = [];
        foreach ($kinds as $kind) {
            $taskTypeId = $taskTypeByKind[$kind] ?? null;
            if (! is_string($taskTypeId)) {
                continue;
            }

            $blob = $this->fetchOne($presaleId, $productId, $taskTypeId, $kind);
            if ($blob !== null) {
                $documents[] = $blob;
            }
        }

        return $documents;
    }

    /**
     * Un intento de captura: pide la URL pre-firmada y descarga el PDF. Si el documento
     * todavía se está generando (sin URL) devuelve null (= pendiente) y el job reintenta
     * en el próximo minuto. Best-effort.
     *
     * @return array{kind: string, filename: string, mime: string, contents: string}|null
     */
    private function fetchOne(int $presaleId, string $productId, string $taskTypeId, string $kind): ?array
    {
        try {
            $url = $this->requestDocumentUrl($presaleId, $productId, $taskTypeId);

            if ($url === '') {
                Log::warning('[VisredDocument] documento todavía sin result.url (pendiente, reintenta el job)', [
                    'task_type_id' => $taskTypeId,
                ]);

                return null;
            }

            return $this->download($url, $taskTypeId, $kind);
        } catch (Throwable $e) {
            Log::warning('[VisredDocument] captura de documento falló (best-effort)', [
                'task_type_id' => $taskTypeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Un POST a `/v1/documents/`: devuelve la URL pre-firmada si el documento ya está
     * listo, o '' si todavía se está generando.
     */
    private function requestDocumentUrl(int $presaleId, string $productId, string $taskTypeId): string
    {
        $task = $this->client->post(self::DOCUMENTS_PATH, [
            'presale_id' => $presaleId,
            'product_id' => $productId,
            'task_type_id' => $taskTypeId,
        ]);

        $result = $task['result'] ?? null;

        return is_array($result) && is_scalar($result['url'] ?? null) ? (string) $result['url'] : '';
    }

    /**
     * @return array{kind: string, filename: string, mime: string, contents: string}|null
     */
    private function download(string $url, string $taskTypeId, string $kind): ?array
    {
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
    }
}
