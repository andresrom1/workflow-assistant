<?php

namespace App\Jobs;

use App\AI\Agents\IngestaExtractorAgent;
use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Models\IngestedDocument;
use App\Services\IngestaDocumentoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Clasifica y extrae los campos de un documento ingestado (v2), reemplazando al parser
 * regex por compañía del cliente local. Ver docs/v3/04-ingesta-local-documentos.md.
 *
 * Flujo: lee `payload.texto` (subido por el ingestor, extraído con pdfplumber) → LLM
 * (`IngestaExtractorAgent`) → valida CADA campo determinísticamente (nunca confía ciego
 * en el LLM — "validar-o-null", heredado del parser v5) → mapea la clase a
 * `PolicyDocumentKind`/`IngestaStatus` → persiste vía `IngestaDocumentoService::applyExtraction()`.
 *
 * Degradación: cualquier fallo (texto vacío, LLM caído, JSON inparseable tras agotar
 * reintentos) deja la fila en `Pendiente` con campos null — el mismo comportamiento que
 * ya existía para documentos "ciegos" antes de este job. Nunca se pierde nada: el PDF ya
 * está en R2 desde `IngestaDocumentoService::stage()`.
 */
class ExtractIngestedDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /** Clase LLM → PolicyDocumentKind. Pertenecen al corpus de pólizas. */
    private const CORPUS_KIND_MAP = [
        'poliza' => PolicyDocumentKind::Poliza,
        'certificado' => PolicyDocumentKind::Certificado,
        'endoso' => PolicyDocumentKind::Endoso,
        'cupon' => PolicyDocumentKind::Cupon,
        'tarjeta_circulacion' => PolicyDocumentKind::CirculationCard,
    ];

    /** Clases que NO documentan una póliza emitida: se descartan solas. */
    private const NON_CORPUS_CLASSES = [
        'factura', 'recibo', 'cotizacion', 'denuncia_siniestro',
        'resumen_cuenta', 'manual_comercial', 'otro_no_poliza',
    ];

    public function __construct(
        public IngestedDocument $document,
    ) {
        // Conexión con retry_after=360 (> timeout=120) y cola `documents` con worker
        // dedicado, igual que ExtractCoverageDocumentText: la extracción LLM no compite
        // con el hot-path de WhatsApp ni es reclamada por otro worker mientras corre.
        $this->onConnection('database_long');
    }

    public function handle(IngestaExtractorAgent $extractor, IngestaDocumentoService $ingesta): void
    {
        // Idempotencia: si ya se resolvió (re-dispatch, reintento tardío), no repetir.
        if ($this->document->status !== IngestaStatus::EnExtraccion) {
            return;
        }

        $texto = trim((string) data_get($this->document->payload, 'texto'));

        if ($texto === '') {
            $ingesta->applyExtraction($this->document, $this->emptyExtraction('sin_texto'));

            Log::info('ExtractIngestedDocument: sin texto, degradado a pendiente', [
                'ingested_document_id' => $this->document->id,
            ]);

            return;
        }

        $prompt = "NOMBRE DE ARCHIVO: {$this->document->original_filename}\n\nTEXTO:\n"
            .mb_substr($texto, 0, config('ingesta.max_text_chars'));

        $response = $extractor->prompt($prompt);
        $decoded = $this->parseJson($response->text);

        if ($decoded === null) {
            throw new RuntimeException('IngestaExtractorAgent: respuesta no parseable como JSON.');
        }

        $extraccion = $this->validar($decoded, $texto);

        $ingesta->applyExtraction($this->document, $extraccion);

        Log::info('ExtractIngestedDocument: completado', [
            'ingested_document_id' => $this->document->id,
            'clase' => $extraccion['clase_cruda'],
            'kind' => $extraccion['kind']->value,
            'status' => $extraccion['status']->value,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ExtractIngestedDocument: failed', [
            'ingested_document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // La extracción nunca bloquea el flujo: peor caso, el doc queda pendiente y
        // ciego (igual que un documento "ciego" del parser v5) para completar a mano.
        if ($this->document->status === IngestaStatus::EnExtraccion) {
            $this->document->update(['status' => IngestaStatus::Pendiente]);
        }
    }

    /**
     * Extrae el primer `{...}` balanceado por posición de llaves (mismo patrón que
     * VisredQuotabilityResolver::parseDecision — más robusto que un regex de fences
     * ```json, tolera texto antes/después del JSON).
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Valida cada campo determinísticamente ("validar-o-null") y mapea la clase del LLM
     * a `PolicyDocumentKind`/`IngestaStatus`. El LLM nunca es la última palabra.
     *
     * @param  array<string, mixed>  $raw
     * @return array{
     *     kind: PolicyDocumentKind, status: IngestaStatus, clase_cruda: string,
     *     compania: ?string, numero_poliza: ?string, endoso_numero: ?string,
     *     tomador: array<string, mixed>, riesgo: array<string, mixed>,
     *     fechas: array<string, mixed>, campos_no_extraidos: list<string>,
     *     razon_descarte: ?string,
     * }
     */
    private function validar(array $raw, string $texto): array
    {
        $clase = mb_strtolower(trim((string) ($raw['clase'] ?? '')));
        $tomadorRaw = (array) ($raw['tomador'] ?? []);
        $riesgoRaw = (array) ($raw['riesgo'] ?? []);
        $fechasRaw = (array) ($raw['fechas'] ?? []);

        // El CUIT del emisor en el texto es señal MÁS fuerte que el nombre que diga el
        // LLM (hay documentos —cupones— que no nombran a la compañía, solo su CUIT).
        $compania = $this->companiaPorCuitDelEmisor($texto)
            ?? $this->normalizarCompania($this->nullableString($raw['compania'] ?? null));
        $numeroPoliza = $this->validarNumero($this->nullableString($raw['numero_poliza'] ?? null));
        $documentoNumero = $this->validarDocumento($this->nullableString($tomadorRaw['documento_numero'] ?? null));
        $patente = $this->validarPatente($this->nullableString($riesgoRaw['patente'] ?? null));
        $firstName = $this->nullableString($tomadorRaw['first_name'] ?? null);
        $lastName = $this->nullableString($tomadorRaw['last_name'] ?? null);
        $razonSocial = $this->nullableString($tomadorRaw['razon_social'] ?? null);
        $vigenciaHasta = $this->validarFecha($this->nullableString($fechasRaw['vigencia_hasta'] ?? null));

        [$kind, $status, $razonDescarte] = $this->resolverDestino($clase);

        $camposClave = [
            'numero_poliza' => $numeroPoliza,
            'documento_numero' => $documentoNumero,
            'nombre_tomador' => $firstName ?: ($lastName ?: $razonSocial),
            'patente' => $patente,
            'vigencia_hasta' => $vigenciaHasta,
        ];

        return [
            'kind' => $kind,
            'status' => $status,
            'clase_cruda' => $clase !== '' ? $clase : 'otro_no_poliza',
            'compania' => $compania,
            'numero_poliza' => $numeroPoliza,
            'endoso_numero' => $this->nullableString($raw['endoso_numero'] ?? null),
            'tomador' => [
                'tipo_persona' => $this->enumOrNull($this->nullableString($tomadorRaw['tipo_persona'] ?? null), ['fisica', 'juridica']),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'razon_social' => $razonSocial,
                'documento_tipo' => $this->enumOrNull(
                    $this->nullableString($tomadorRaw['documento_tipo'] ?? null),
                    ['DNI', 'CUIT', 'CUIL'],
                    caseSensitive: false,
                ),
                'documento_numero' => $documentoNumero,
            ],
            'riesgo' => [
                'tipo' => 'vehicle',
                'patente' => $patente,
                'marca' => $this->nullableString($riesgoRaw['marca'] ?? null),
                'modelo' => $this->nullableString($riesgoRaw['modelo'] ?? null),
                'version' => null,
                'year' => $this->nullableString($riesgoRaw['year'] ?? null),
                'combustible' => $this->nullableString($riesgoRaw['combustible'] ?? null),
                'uso' => $this->nullableString($riesgoRaw['uso'] ?? null),
                'codigo_postal' => null,
            ],
            'fechas' => [
                'emision' => $this->validarFecha($this->nullableString($fechasRaw['emision'] ?? null)),
                'vigencia_desde' => $this->validarFecha($this->nullableString($fechasRaw['vigencia_desde'] ?? null)),
                'vigencia_hasta' => $vigenciaHasta,
            ],
            'campos_no_extraidos' => array_keys(array_filter(
                $camposClave,
                fn (?string $v): bool => $v === null || $v === '',
            )),
            'razon_descarte' => $razonDescarte,
        ];
    }

    /**
     * @return array{0: PolicyDocumentKind, 1: IngestaStatus, 2: ?string}
     */
    private function resolverDestino(string $clase): array
    {
        if (isset(self::CORPUS_KIND_MAP[$clase])) {
            return [self::CORPUS_KIND_MAP[$clase], IngestaStatus::Pendiente, null];
        }

        if (in_array($clase, self::NON_CORPUS_CLASSES, true)) {
            return [PolicyDocumentKind::Otro, IngestaStatus::DescartadoAuto, $clase];
        }

        // Clase desconocida/vacía: conservador, cae a Pendientes para que el humano decida
        // (el error barato es un documento de más en la cola; el caro es descartar una
        // póliza real).
        return [PolicyDocumentKind::Otro, IngestaStatus::Pendiente, null];
    }

    /**
     * Degradación cuando no hay texto para mandar al LLM: todo null, cae a Pendientes
     * igual que un documento "ciego" bajo el parser v5.
     *
     * @return array{
     *     kind: PolicyDocumentKind, status: IngestaStatus, clase_cruda: string,
     *     compania: ?string, numero_poliza: ?string, endoso_numero: ?string,
     *     tomador: array<string, mixed>, riesgo: array<string, mixed>,
     *     fechas: array<string, mixed>, campos_no_extraidos: list<string>,
     *     razon_descarte: ?string,
     * }
     */
    private function emptyExtraction(string $razon): array
    {
        return [
            'kind' => PolicyDocumentKind::Otro,
            'status' => IngestaStatus::Pendiente,
            'clase_cruda' => $razon,
            'compania' => null,
            'numero_poliza' => null,
            'endoso_numero' => null,
            'tomador' => [
                'tipo_persona' => null, 'first_name' => null, 'last_name' => null,
                'razon_social' => null, 'documento_tipo' => null, 'documento_numero' => null,
            ],
            'riesgo' => [
                'tipo' => 'vehicle', 'patente' => null, 'marca' => null, 'modelo' => null,
                'version' => null, 'year' => null, 'combustible' => null, 'uso' => null,
                'codigo_postal' => null,
            ],
            'fechas' => ['emision' => null, 'vigencia_desde' => null, 'vigencia_hasta' => null],
            'campos_no_extraidos' => ['numero_poliza', 'documento_numero', 'nombre_tomador', 'patente', 'vigencia_hasta'],
            'razon_descarte' => null,
        ];
    }

    // ---------- validadores determinísticos ("validar-o-null") ----------

    private function validarPatente(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $v = mb_strtoupper(trim($v));

        return preg_match('/^([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2}|[A-Z]\d{3}[A-Z]{3})$/', $v) === 1 ? $v : null;
    }

    private function validarDocumento(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $d = preg_replace('/\D/', '', $v) ?? '';

        if (! in_array(mb_strlen($d), [8, 11], true)) {
            return null;
        }

        /** @var array<string, string> $cuitsAseguradoras */
        $cuitsAseguradoras = config('ingesta.company_cuits', []);
        /** @var list<string> $otrosEmisores */
        $otrosEmisores = config('ingesta.other_issuer_cuits', []);

        $esEmisor = array_key_exists($d, $cuitsAseguradoras) || in_array($d, $otrosEmisores, true);

        return $esEmisor ? null : $d;
    }

    /**
     * Detección FUERTE de compañía por el CUIT del emisor presente en el texto (misma
     * señal que usaba parser.py v5): busca CUITs como token con límites (formateado
     * `NN-NNNNNNNN-N` o 11 dígitos contiguos) y los matchea contra el mapa de
     * aseguradoras conocidas. Si aparecen CUITs de MÁS de una aseguradora (p. ej. la
     * tarjeta verde Mercosur lista varias como representantes), es ambiguo → null (se
     * usa lo que dijo el LLM).
     */
    private function companiaPorCuitDelEmisor(string $texto): ?string
    {
        preg_match_all('/\b\d{2}-\d{8}-\d\b/', $texto, $formateados);
        preg_match_all('/\b\d{11}\b/', $texto, $contiguos);

        $cuits = array_unique(array_merge(
            array_map(fn (string $c): string => preg_replace('/\D/', '', $c) ?? '', $formateados[0]),
            $contiguos[0],
        ));

        /** @var array<string, string> $mapa */
        $mapa = config('ingesta.company_cuits', []);

        $matches = array_values(array_unique(array_intersect_key(
            $mapa,
            array_flip(array_filter($cuits, fn (string $c): bool => array_key_exists($c, $mapa))),
        )));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function validarFecha(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m) !== 1) {
            return null;
        }

        [, $y, $mo, $d] = $m;

        if (! checkdate((int) $mo, (int) $d, (int) $y)) {
            return null;
        }

        return ((int) $y >= 2000 && (int) $y <= 2035) ? $v : null;
    }

    private function validarNumero(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $v = trim($v);
        $alnum = preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '';

        return mb_strlen($alnum) >= 5 ? $v : null;
    }

    private function normalizarCompania(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }

        $norm = mb_strtoupper(Str::ascii($v));

        /** @var array<string, string> $aliases */
        $aliases = config('ingesta.company_aliases', []);

        foreach ($aliases as $alias => $canonical) {
            if (str_contains($norm, $alias)) {
                return $canonical;
            }
        }

        return $v;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enumOrNull(?string $v, array $allowed, bool $caseSensitive = true): ?string
    {
        if ($v === null) {
            return null;
        }

        foreach ($allowed as $option) {
            if ($caseSensitive ? $v === $option : mb_strtolower($v) === mb_strtolower($option)) {
                return $caseSensitive ? $v : $option;
            }
        }

        return null;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $v = trim((string) $v);

        return $v !== '' && mb_strtolower($v) !== 'null' ? $v : null;
    }
}
