<?php

namespace App\Services;

use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Jobs\ExtractIngestedDocument;
use App\Models\IngestedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Estaciona en staging un documento de póliza ingestado por el ingestor local, para
 * confirmación humana antes de materializar la cadena Customer→Risk→Poliza→PolicyDocument.
 *
 * Agnóstico de canal/proveedor: recibe el contrato ya validado (array neutro, sin DTO) +
 * el PDF. NO conoce HTTP ni Sanctum. Idempotente por `archivo.hash_sha256`: un reenvío del
 * mismo PDF devuelve la fila existente sin duplicar ni re-subir. Ver doc v3/04 §4.
 *
 * v2: el contrato de entrada ya no trae documento/tomador/riesgo/fechas (eso lo produce
 * el LLM). `stage()` solo persiste el PDF + el texto y despacha la extracción
 * ({@see ExtractIngestedDocument}); {@see applyExtraction()} es lo que ese job invoca al
 * terminar para reescribir el payload con el shape del contrato v1 y resolver el status.
 */
class IngestaDocumentoService
{
    /**
     * @param  array{schema_version: int, archivo: array<string, mixed>, texto: ?string}  $contract
     * @return array{status: string, ingested_document_id: int, pendiente: bool}
     */
    public function stage(array $contract, UploadedFile $file): array
    {
        $hash = (string) data_get($contract, 'archivo.hash_sha256');

        // Dedup: el cliente reintenta tras un 5xx o se reinstala — la idempotencia es del server.
        $existing = IngestedDocument::firstWhere('hash_sha256', $hash);

        if ($existing !== null) {
            return [
                'status' => 'duplicate',
                'ingested_document_id' => $existing->id,
                'pendiente' => in_array($existing->status, [IngestaStatus::EnExtraccion, IngestaStatus::Pendiente], true),
            ];
        }

        // El PDF crudo va a R2 (mismo disco que el resto de documentos). Path por hash:
        // estable e idempotente aunque dos corridas suban el mismo archivo.
        $path = "ingesta/{$hash}.pdf";
        Storage::disk('r2')->put($path, $file->get());

        $document = IngestedDocument::create([
            'hash_sha256' => $hash,
            // Placeholder: la clasificación real la resuelve ExtractIngestedDocument.
            'kind' => PolicyDocumentKind::Otro,
            'status' => IngestaStatus::EnExtraccion,
            'original_filename' => data_get($contract, 'archivo.nombre_original'),
            'storage_path' => $path,
            'storage_url' => Storage::disk('r2')->url($path),
            'detectado_en' => data_get($contract, 'archivo.detectado_en'),
            'payload' => $contract, // {schema_version, archivo, texto}
        ]);

        ExtractIngestedDocument::dispatch($document);

        return [
            'status' => 'staged',
            'ingested_document_id' => $document->id,
            'pendiente' => true,
        ];
    }

    /**
     * Aplica el resultado ya validado de {@see ExtractIngestedDocument} sobre un
     * documento estacionado: reescribe `payload` con el shape del contrato v1 (§2 del doc
     * v3/04) —para que `IngestaPendientesController`/`IngestaConfirmacionService` sigan
     * funcionando sin cambios— y actualiza las columnas denormalizadas + el status
     * (`Pendiente` o `DescartadoAuto`) que ya resolvió el job.
     *
     * @param  array{
     *     kind: PolicyDocumentKind, status: IngestaStatus, clase_cruda: string,
     *     compania: ?string, numero_poliza: ?string, endoso_numero: ?string,
     *     tomador: array<string, mixed>, riesgo: array<string, mixed>,
     *     fechas: array<string, mixed>, campos_no_extraidos: list<string>,
     *     razon_descarte: ?string,
     * }  $extraccion
     */
    public function applyExtraction(IngestedDocument $doc, array $extraccion): void
    {
        $payload = $doc->payload;

        $payload['documento'] = [
            'kind' => $extraccion['kind']->value,
            'compania' => $extraccion['compania'],
            'numero_poliza' => $extraccion['numero_poliza'],
            'endoso_numero' => $extraccion['endoso_numero'],
        ];
        $payload['tomador'] = $extraccion['tomador'];
        $payload['riesgo'] = $extraccion['riesgo'];
        $payload['fechas'] = $extraccion['fechas'];
        $payload['extraccion'] = [
            'parser' => 'deepseek-v2',
            'clase' => $extraccion['clase_cruda'],
            'campos_no_extraidos' => $extraccion['campos_no_extraidos'],
            'razon_descarte' => $extraccion['razon_descarte'],
        ];

        $doc->update([
            'kind' => $extraccion['kind'],
            'compania' => $extraccion['compania'],
            'numero_poliza' => $extraccion['numero_poliza'],
            'documento_numero' => $extraccion['tomador']['documento_numero'] ?? null,
            'patente' => $extraccion['riesgo']['patente'] ?? null,
            'status' => $extraccion['status'],
            'payload' => $payload,
            'campos_no_extraidos' => $extraccion['campos_no_extraidos'],
        ]);
    }
}
