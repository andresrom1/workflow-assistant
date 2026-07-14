<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractIngestedDocument;
use App\Services\IngestaDocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Recibe los documentos de póliza que sube el ingestor local (repo `ingestor/`, Python,
 * scanner+uploader v6) por `multipart/form-data`: campo `metadata` (JSON string del
 * contrato v2) + campo `file` (PDF original). Autenticado con token Sanctum del productor.
 *
 * v2: el cliente ya NO extrae campos (eso lo hacía el parser regex v5, retirado). Manda
 * texto plano (pdfplumber, primeras páginas) + el PDF; la clasificación/extracción corre
 * server-side vía LLM en {@see ExtractIngestedDocument}, despachado desde
 * {@see IngestaDocumentoService::stage()}.
 *
 * Filosofía de validación (se mantiene de v1): MINIMIZAR los 422. Un 422 hace que el
 * ingestor cuente el archivo como error y lo reintente al día siguiente. Sólo se rechaza
 * lo genuinamente improcesable (envoltorio roto, sin hash para dedup, schema desconocido).
 * Ver docs/v3/04-ingesta-local-documentos.md.
 */
class PolicyDocumentIngestaController extends Controller
{
    public function __construct(
        private readonly IngestaDocumentoService $ingesta,
    ) {}

    public function store(Request $request): JsonResponse
    {
        // 1. Envoltorio: lo único que el framework rechaza con 422 de forma estándar.
        $request->validate([
            'metadata' => ['required', 'string'],
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:51200'],
        ]);

        // 2. El contrato viaja como string JSON dentro de un form field, no como body JSON.
        $contract = json_decode($request->string('metadata')->value(), true);

        if (! is_array($contract)) {
            throw ValidationException::withMessages([
                'metadata' => 'El campo metadata no es un JSON válido.',
            ]);
        }

        // 3. Invariantes duras: sin éstas el documento es improcesable (no es "incompleto").
        $hash = data_get($contract, 'archivo.hash_sha256');

        if ((int) data_get($contract, 'schema_version') !== 2) {
            throw ValidationException::withMessages([
                'metadata' => 'schema_version no soportado (se espera 2).',
            ]);
        }

        if (! is_string($hash) || trim($hash) === '') {
            throw ValidationException::withMessages([
                'metadata' => 'Falta archivo.hash_sha256 (requerido para idempotencia).',
            ]);
        }

        // 4. Degradación: `texto` es opcional. Si vino con un tipo inesperado, se ignora
        //    en vez de rechazar el documento (mismo espíritu que el resto del endpoint):
        //    el job de extracción ya sabe degradar cuando no hay texto.
        $texto = data_get($contract, 'texto');
        $contract['texto'] = is_string($texto) ? $texto : null;

        // 5. El controller no crea nada: delega el estacionamiento en Pendientes.
        $result = $this->ingesta->stage($contract, $request->file('file'));

        // Duplicado → 200 idempotente (no 4xx: un 4xx haría que el cliente lo reintente
        // mañana); alta nueva → 201. Ambos son 2xx, que es lo que el ingestor exige.
        $status = $result['status'] === 'duplicate' ? 200 : 201;

        return response()->json($result, $status);
    }
}
