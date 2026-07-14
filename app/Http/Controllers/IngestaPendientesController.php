<?php

namespace App\Http\Controllers;

use App\Enums\IngestaStatus;
use App\Enums\PolizaEstado;
use App\Models\IngestedDocument;
use App\Repositories\CustomerRepository;
use App\Services\IngestaConfirmacionService;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cola de Pendientes del ingestor local: revisa lo que subió el script Python y, con
 * confirmación humana, materializa la cadena Customer→Risk→Poliza→PolicyDocument
 * ({@see IngestaConfirmacionService}). Documentos del mismo contrato se agrupan por
 * `numero_poliza` (fallback `patente`) para revisarlos y confirmarlos juntos. Ver doc v3/04 §4-§5.
 *
 * Es distinta de `/documentacion-pendiente` (checklist de completitud sobre pólizas ya
 * existentes): acá lo que está pendiente es el **alta**, no un documento faltante.
 */
class IngestaPendientesController extends Controller
{
    public function __construct(
        private readonly IngestaConfirmacionService $confirmacion,
        private readonly CustomerRepository $customers,
    ) {}

    public function index(): Response
    {
        $pendientes = IngestedDocument::query()
            ->where('status', IngestaStatus::Pendiente)
            ->orderByDesc('detectado_en')
            ->orderByDesc('id')
            ->get();

        // Agrupar por contrato: compañía + número normalizado (solo dígitos), sino
        // patente, sino el id (suelto). El número normalizado evita que dos compañías con
        // el mismo número colisionen y que variantes de formato del mismo número
        // (LLM: "1.912.367" vs "458 1.912.367", o guiones vs sin guiones) partan el
        // contrato en dos grupos.
        $numeroKey = fn (IngestedDocument $d): ?string => ($num = preg_replace('/\D/', '', (string) $d->numero_poliza)) !== ''
            ? 'num:'.mb_strtolower((string) $d->compania).':'.$num
            : null;

        // Fusión: documentos sin número (cupón, tarjeta vieja) que comparten patente con
        // un contrato ya identificado por otro documento del grupo se unen a ESE grupo en
        // vez de quedar sueltos en su propio "pat:X".
        $patenteAContrato = [];
        foreach ($pendientes as $d) {
            $key = $numeroKey($d);
            if ($key !== null && $d->patente !== null && ! isset($patenteAContrato[$d->patente])) {
                $patenteAContrato[$d->patente] = $key;
            }
        }

        $grupos = $pendientes
            ->groupBy(function (IngestedDocument $d) use ($numeroKey, $patenteAContrato): string {
                $key = $numeroKey($d);

                return $key ?? $patenteAContrato[$d->patente] ?? ($d->patente !== null ? "pat:{$d->patente}" : "id:{$d->id}");
            })
            ->map(function (Collection $docs, string $key): array {
                /** @var IngestedDocument $head */
                $head = $docs->first();
                $merge = $this->mergeContrato($docs);
                $sugerida = $this->confirmacion->sugerirContratoAnterior($head);
                $faltantes = $docs
                    ->flatMap(fn (IngestedDocument $d): array => $d->campos_no_extraidos ?? [])
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'numero_poliza' => $merge['numero_poliza'],
                    'compania' => $merge['company'],
                    'patente' => $merge['patente'],
                    'resumen' => [
                        'tomador' => $merge['tomador'],
                        'documento_numero' => $merge['documento_numero'],
                        'patente' => $merge['patente'],
                        'vehiculo' => $merge['vehiculo'],
                        'vigencia_desde' => $merge['vigencia_desde'],
                        'vigencia_hasta' => $merge['vigencia_hasta'],
                    ],
                    'prefill' => [
                        'documento_numero' => $merge['documento_numero'],
                        'document_type' => $this->tipoDocumento($merge['documento_tipo'], $merge['documento_numero']),
                        'person_type' => $this->tipoPersona($merge['tipo_persona'], $merge['documento_numero']),
                        'first_name' => $merge['first_name'],
                        'last_name' => $merge['last_name'],
                        'numero_poliza' => $merge['numero_poliza'],
                        'company' => $merge['company'],
                        'patente' => $merge['patente'],
                        'vigencia_desde' => $merge['vigencia_desde'],
                        'vigencia_hasta' => $merge['vigencia_hasta'],
                    ],
                    'campos_faltantes' => $faltantes,
                    'faltantes_count' => count($faltantes),
                    'contrato_anterior_sugerido' => $sugerida === null ? null : [
                        'id' => $sugerida->id,
                        'numero' => $sugerida->numero,
                        'vigencia' => $sugerida->vigencia?->toDateString(),
                    ],
                    'documentos' => $docs->map(fn (IngestedDocument $d): array => [
                        'id' => $d->id,
                        'kind' => $d->kind->value,
                        'kind_label' => $d->kind->label(),
                        'compania' => $d->compania,
                        'numero_poliza' => $d->numero_poliza,
                        'documento_numero' => $d->documento_numero,
                        'patente' => $d->patente,
                        'tomador' => trim((string) data_get($d->payload, 'tomador.first_name').' '.(string) data_get($d->payload, 'tomador.last_name')) ?: data_get($d->payload, 'tomador.razon_social'),
                        'vigencia_desde' => data_get($d->payload, 'fechas.vigencia_desde'),
                        'vigencia_hasta' => data_get($d->payload, 'fechas.vigencia_hasta'),
                        'campos_no_extraidos' => $d->campos_no_extraidos ?? [],
                        'original_filename' => $d->original_filename,
                        'preview_url' => Storage::disk('r2')->temporaryUrl($d->storage_path, now()->addMinutes(30), [
                            'ResponseContentDisposition' => 'inline',
                            'ResponseContentType' => 'application/pdf',
                        ]),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('faltantes_count')
            ->values()
            ->all();

        return Inertia::render('PolicyDocuments/PendientesIngesta', [
            'grupos' => $grupos,
            'estados' => collect(PolizaEstado::paraIngesta())
                ->map(fn (PolizaEstado $e): array => ['value' => $e->value, 'label' => $e->label()])
                ->all(),
        ]);
    }

    public function confirm(Request $request, IngestedDocument $ingestedDocument): RedirectResponse
    {
        $overrides = $request->validate($this->overrideRules());

        $this->confirmacion->confirm($ingestedDocument, $overrides);

        return back()->with('flash', ['success' => 'Alta confirmada y materializada.']);
    }

    public function confirmContrato(Request $request): RedirectResponse
    {
        $data = $request->validate($this->overrideRules() + [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:ingested_documents,id'],
        ]);

        $this->confirmacion->confirmContrato($data['ids'], Arr::except($data, ['ids']));

        return back()->with('flash', ['success' => 'Contrato confirmado y materializado.']);
    }

    public function discardContrato(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:ingested_documents,id'],
        ]);

        $this->confirmacion->discardContrato($data['ids']);

        return back()->with('flash', ['success' => 'Contrato descartado.']);
    }

    public function discard(IngestedDocument $ingestedDocument): RedirectResponse
    {
        $this->confirmacion->discard($ingestedDocument);

        return back()->with('flash', ['success' => 'Documento descartado.']);
    }

    /**
     * Reglas de los campos corregibles por el admin, compartidas por confirm y confirmContrato.
     *
     * @return array<string, array<int, mixed>>
     */
    private function overrideRules(): array
    {
        return [
            'documento_numero' => ['nullable', 'string', 'max:20'],
            'document_type' => ['nullable', Rule::in(['dni', 'cuit', 'cuil'])],
            'person_type' => ['nullable', Rule::in(['fisica', 'juridica'])],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'numero_poliza' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:120'],
            'patente' => ['nullable', 'string', 'max:12'],
            'vigencia_desde' => ['nullable', 'date'],
            'vigencia_hasta' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(array_map(fn (PolizaEstado $e): string => $e->value, PolizaEstado::paraIngesta()))],
            'contrato_anterior_id' => ['nullable', 'integer', 'exists:polizas,id'],
        ];
    }

    /**
     * Lookup del titular por la clave de identidad canónica ({@see DocumentoIdentidad}): dice si
     * el cliente ya existe (para adjuntar) o es nuevo, con lo mismo que hará el confirm.
     */
    public function buscarCliente(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documento' => ['required', 'string', 'max:20'],
            'document_type' => ['nullable', Rule::in(['dni', 'cuit', 'cuil'])],
            'person_type' => ['nullable', Rule::in(['fisica', 'juridica'])],
        ]);

        $clave = DocumentoIdentidad::clave($data['documento'], $data['document_type'] ?? null, $data['person_type'] ?? null);
        $cliente = $clave === null ? null : $this->customers->findByDocumentoKey($clave);

        if ($cliente === null) {
            return response()->json(['existe' => false, 'cliente' => null]);
        }

        // Clientes viejos pueden tener solo `name` (sin split): derivamos nombre/apellido para
        // que el autocompletado del form tenga qué poner (primer token / resto, igual que la
        // migración de holder fields).
        $first = $cliente->first_name;
        $last = $cliente->last_name;
        if (($first === null || $first === '') && (string) $cliente->name !== '') {
            $parts = preg_split('/\s+/', trim((string) $cliente->name), 2);
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return response()->json([
            'existe' => true,
            'cliente' => [
                'first_name' => $first,
                'last_name' => $last,
                'name' => $cliente->name,
                'email' => $cliente->email,
            ],
        ]);
    }

    /**
     * Tipo de documento para el select: el declarado por el parser (normalizado), o inferido por
     * la longitud del número (11 dígitos → cuit, si no dni).
     */
    private function tipoDocumento(?string $raw, ?string $numero): ?string
    {
        $tipo = strtolower(trim((string) $raw));
        if (in_array($tipo, ['dni', 'cuit', 'cuil'], true)) {
            return $tipo;
        }

        $norm = DocumentoIdentidad::normalizar($numero);
        if ($norm === null) {
            return null;
        }

        return strlen($norm) === 11 ? 'cuit' : 'dni';
    }

    /**
     * Tipo de persona para el select: el declarado por el parser, o inferido por el prefijo del
     * CUIT/CUIL.
     */
    private function tipoPersona(?string $raw, ?string $numero): ?string
    {
        $tipo = strtolower(trim((string) $raw));
        if (in_array($tipo, ['fisica', 'juridica'], true)) {
            return $tipo;
        }

        return DocumentoIdentidad::inferirTipoPersona($numero);
    }

    /**
     * Mergea los datos de contrato tomando el primer valor no-nulo across docs del grupo
     * (para prellenar el form y enriquecer el resumen de la tarjeta).
     *
     * @param  Collection<int, IngestedDocument>  $docs
     * @return array<string, string|null>
     */
    private function mergeContrato(Collection $docs): array
    {
        $firstNonNull = function (callable $accessor) use ($docs): ?string {
            foreach ($docs as $doc) {
                $value = $accessor($doc);
                if ($value !== null && trim((string) $value) !== '') {
                    return (string) $value;
                }
            }

            return null;
        };

        $firstName = $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'tomador.first_name'));
        $lastName = $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'tomador.last_name'));
        $razonSocial = $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'tomador.razon_social'));
        $tomador = trim((string) $firstName.' '.(string) $lastName) ?: $razonSocial;

        $vehiculo = trim(implode(' ', array_filter([
            $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'riesgo.marca')),
            $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'riesgo.modelo')),
            $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'riesgo.year')),
        ]))) ?: null;

        return [
            'documento_numero' => $firstNonNull(fn (IngestedDocument $d) => $d->documento_numero),
            'documento_tipo' => $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'tomador.documento_tipo')),
            'tipo_persona' => $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'tomador.tipo_persona')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tomador' => $tomador,
            'numero_poliza' => $firstNonNull(fn (IngestedDocument $d) => $d->numero_poliza),
            'company' => $firstNonNull(fn (IngestedDocument $d) => $d->compania),
            'patente' => $firstNonNull(fn (IngestedDocument $d) => $d->patente),
            'vigencia_desde' => $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'fechas.vigencia_desde')),
            'vigencia_hasta' => $firstNonNull(fn (IngestedDocument $d) => data_get($d->payload, 'fechas.vigencia_hasta')),
            'vehiculo' => $vehiculo,
        ];
    }
}
