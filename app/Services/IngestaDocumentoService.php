<?php

namespace App\Services;

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
 */
class IngestaDocumentoService
{
    /**
     * @param  array<string, mixed>  $contract  contrato §2 ya validado y con `kind` resuelto
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
                'pendiente' => $existing->status->value === 'pendiente',
            ];
        }

        // El PDF crudo va a R2 (mismo disco que el resto de documentos). Path por hash:
        // estable e idempotente aunque dos corridas suban el mismo archivo.
        $path = "ingesta/{$hash}.pdf";
        Storage::disk('r2')->put($path, $file->get());

        $document = IngestedDocument::create([
            'hash_sha256' => $hash,
            'kind' => data_get($contract, 'documento.kind'),
            'compania' => data_get($contract, 'documento.compania'),
            'numero_poliza' => data_get($contract, 'documento.numero_poliza'),
            'documento_numero' => data_get($contract, 'tomador.documento_numero'),
            'patente' => data_get($contract, 'riesgo.patente'),
            'status' => 'pendiente',
            'original_filename' => data_get($contract, 'archivo.nombre_original'),
            'storage_path' => $path,
            'storage_url' => Storage::disk('r2')->url($path),
            'detectado_en' => data_get($contract, 'archivo.detectado_en'),
            'payload' => $contract,
            'campos_no_extraidos' => data_get($contract, 'extraccion.campos_no_extraidos'),
        ]);

        return [
            'status' => 'staged',
            'ingested_document_id' => $document->id,
            'pendiente' => true,
        ];
    }
}
