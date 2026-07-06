<?php

namespace App\Services;

use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\IngestedDocument;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Risk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Materializa un documento estacionado por el ingestor local (`ingested_documents`,
 * estado `pendiente`) en la cadena `Customer→Risk→Poliza→PolicyDocument`, tras la
 * confirmación humana en Pendientes (doc v3/04 §4-§5).
 *
 * Modo de arranque: nada se materializa solo. El admin confirma/corrige los campos clave
 * (`$overrides`) y, si es renovación, el `contrato_anterior_id` sugerido (inferido por
 * patente+compañía, ver {@see sugerirContratoAnterior()}). Agnóstico de canal: el alta de
 * cliente pasa SIEMPRE por la dedup ({@see CustomerMergeService}), nunca crea `customers`
 * directo. Idempotente a nivel contrato: documentos del mismo contrato (mismo
 * company+numero) se acumulan sobre la misma `Poliza`.
 */
class IngestaConfirmacionService
{
    public function __construct(
        private readonly PolicyChainResolver $chain,
    ) {}

    /**
     * Confirma un documento estacionado y lo materializa.
     *
     * @param  array<string, mixed>  $overrides  campos clave corregidos por el admin
     *                                           (documento_numero, first_name, last_name,
     *                                           numero_poliza, company, patente, estado,
     *                                           contrato_anterior_id)
     */
    public function confirm(IngestedDocument $doc, array $overrides = []): IngestedDocument
    {
        if ($doc->status !== IngestaStatus::Pendiente) {
            throw ValidationException::withMessages([
                'status' => 'Este documento ya fue resuelto.',
            ]);
        }

        return DB::transaction(function () use ($doc, $overrides): IngestedDocument {
            $poliza = $this->resolvePoliza($doc, $overrides);
            $document = $this->attachDocument($poliza, $doc);

            $doc->update([
                'status' => IngestaStatus::Confirmado,
                'poliza_id' => $poliza->id,
                'policy_document_id' => $document->id,
            ]);

            return $doc->refresh();
        });
    }

    /**
     * Confirma un contrato completo: resuelve la póliza UNA sola vez y adjunta todos los
     * documentos del grupo (cada uno preserva su `kind`). La unidad de trabajo del admin es
     * el contrato, no el documento suelto.
     *
     * @param  list<int>  $docIds
     * @param  array<string, mixed>  $overrides  campos clave corregidos por el admin
     */
    public function confirmContrato(array $docIds, array $overrides = []): Poliza
    {
        $docs = IngestedDocument::whereIn('id', $docIds)->get();

        if ($docs->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'No hay documentos para confirmar.']);
        }

        if ($docs->contains(fn (IngestedDocument $d): bool => $d->status !== IngestaStatus::Pendiente)) {
            throw ValidationException::withMessages(['status' => 'Alguno de los documentos ya fue resuelto.']);
        }

        $principal = $this->pickPrincipal($docs);

        return DB::transaction(function () use ($docs, $principal, $overrides): Poliza {
            $poliza = $this->resolvePoliza($principal, $overrides);

            foreach ($docs as $doc) {
                $document = $this->attachDocument($poliza, $doc);
                $doc->update([
                    'status' => IngestaStatus::Confirmado,
                    'poliza_id' => $poliza->id,
                    'policy_document_id' => $document->id,
                ]);
            }

            return $poliza;
        });
    }

    /**
     * Descarta un documento estacionado sin materializar nada (el PDF queda en R2 para
     * auditoría; un job de limpieza puede recogerlo luego si se decide).
     */
    public function discard(IngestedDocument $doc): IngestedDocument
    {
        if ($doc->status !== IngestaStatus::Pendiente) {
            throw ValidationException::withMessages([
                'status' => 'Este documento ya fue resuelto.',
            ]);
        }

        $doc->update(['status' => IngestaStatus::Descartado]);

        return $doc->refresh();
    }

    /**
     * Descarta un contrato completo (todos sus documentos) en una sola transacción.
     *
     * @param  list<int>  $docIds
     */
    public function discardContrato(array $docIds): void
    {
        DB::transaction(function () use ($docIds): void {
            IngestedDocument::whereIn('id', $docIds)
                ->where('status', IngestaStatus::Pendiente)
                ->update(['status' => IngestaStatus::Descartado]);
        });
    }

    /**
     * Documento que aporta la identidad del contrato: preferentemente la póliza con número;
     * si no, el primero con número; si ninguno, el primero del grupo.
     *
     * @param  Collection<int, IngestedDocument>  $docs
     */
    private function pickPrincipal($docs): IngestedDocument
    {
        return $docs->first(fn (IngestedDocument $d): bool => $d->kind === PolicyDocumentKind::Poliza && $d->numero_poliza !== null)
            ?? $docs->first(fn (IngestedDocument $d): bool => $d->numero_poliza !== null)
            ?? $docs->first();
    }

    /**
     * Póliza anterior sugerida cuando el alta es una renovación: la más reciente sobre el
     * mismo Risk (patente) de la misma compañía, distinta de la que se está dando de alta y
     * estructuralmente renovable. Es solo una **sugerencia** para la UI; el humano confirma.
     */
    public function sugerirContratoAnterior(IngestedDocument $doc): ?Poliza
    {
        $patente = trim((string) $doc->patente);
        $company = trim((string) $doc->compania);

        if ($patente === '' || $company === '') {
            return null;
        }

        return Poliza::query()
            ->where('company', $company)
            ->when($doc->numero_poliza !== null, fn ($q) => $q->where('numero', '!=', $doc->numero_poliza))
            ->whereHas('risk', fn ($r) => $r->where('metadata->patente', $patente))
            ->whereDoesntHave('sucesoras')
            ->latest('vigencia')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolvePoliza(IngestedDocument $doc, array $overrides): Poliza
    {
        $company = $this->pick($overrides, 'company', $doc->compania);
        $numero = $this->pick($overrides, 'numero_poliza', $doc->numero_poliza);
        $patente = $this->pick($overrides, 'patente', $doc->patente);

        // 1. Acumular sobre un contrato ya materializado (por company+numero).
        if ($numero !== null && $company !== null) {
            $existing = Poliza::where('company', $company)->where('numero', $numero)->first();
            if ($existing instanceof Poliza) {
                return $existing;
            }
        }

        // 2. Documento sin número (p. ej. tarjeta de circulación): se adjunta al contrato
        //    ya existente del mismo Risk (fallback por patente). No crea cadena nueva.
        if ($numero === null) {
            $poliza = $this->polizaByPatente($patente);
            if ($poliza instanceof Poliza) {
                return $poliza;
            }

            throw ValidationException::withMessages([
                'numero_poliza' => 'Sin número de póliza ni un contrato existente por patente. Confirmá primero el documento principal del contrato.',
            ]);
        }

        // 3. Alta nueva: requiere identidad del tomador para crear el cliente.
        $customer = $this->resolveCustomer($doc, $overrides);
        $risk = $this->resolveRisk($customer, $patente, $doc);
        $estado = $this->resolveEstado($overrides, $doc);

        // Renovación confirmada: la anterior deja de ser vigente antes de crear la nueva
        // (preserva "una vigente por Risk", igual que PolizaService::renovar).
        $contratoAnteriorId = $this->intOrNull($overrides['contrato_anterior_id'] ?? null);
        if ($contratoAnteriorId !== null) {
            Poliza::where('id', $contratoAnteriorId)
                ->where('estado', PolizaEstado::Vigente)
                ->update(['estado' => PolizaEstado::Vencida]);
        }

        $this->assertNoOtherVigente($risk->id, $estado);

        return $risk->polizas()->create([
            'estado' => $estado,
            'numero' => $numero,
            'company' => $company,
            'contrato_anterior_id' => $contratoAnteriorId,
            'emitida_en' => $this->dateOrNull(data_get($doc->payload, 'fechas.emision')),
            'vigencia' => $this->dateOrNull($this->pick($overrides, 'vigencia_hasta', data_get($doc->payload, 'fechas.vigencia_hasta'))),
            'metadata' => array_filter([
                'origen' => 'ingesta_local',
                'vigencia_desde' => $this->pick($overrides, 'vigencia_desde', data_get($doc->payload, 'fechas.vigencia_desde')),
            ], fn ($v): bool => $v !== null),
        ]);
    }

    private function attachDocument(Poliza $poliza, IngestedDocument $doc): PolicyDocument
    {
        return PolicyDocument::create([
            'poliza_id' => $poliza->id,
            'kind' => $doc->kind,
            'storage_path' => $doc->storage_path,
            'storage_url' => $doc->storage_url,
            'original_filename' => $doc->original_filename,
            'source' => PolicyDocumentSource::LocalIngesta,
            'visible_to_client' => true,
            'captured_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolveCustomer(IngestedDocument $doc, array $overrides): Customer
    {
        $dni = $this->pick($overrides, 'documento_numero', $doc->documento_numero);

        if ($dni === null) {
            throw ValidationException::withMessages([
                'documento_numero' => 'Falta el documento del tomador para crear el cliente.',
            ]);
        }

        $documentType = $this->pick($overrides, 'document_type', strtolower((string) data_get($doc->payload, 'tomador.documento_tipo')));
        $personType = $this->pick($overrides, 'person_type', data_get($doc->payload, 'tomador.tipo_persona'));

        return $this->chain->resolveCustomer($dni, [
            'first_name' => $this->pick($overrides, 'first_name', data_get($doc->payload, 'tomador.first_name')),
            'last_name' => $this->pick($overrides, 'last_name', data_get($doc->payload, 'tomador.last_name')),
            'razon_social' => data_get($doc->payload, 'tomador.razon_social'),
        ], $documentType, $personType);
    }

    private function resolveRisk(Customer $customer, ?string $patente, IngestedDocument $doc): Risk
    {
        /** @var array<string, mixed> $riesgo */
        $riesgo = (array) data_get($doc->payload, 'riesgo', []);

        return $this->chain->resolveRisk($customer, (string) $patente, $riesgo);
    }

    private function polizaByPatente(?string $patente): ?Poliza
    {
        $patente = $patente !== null ? trim($patente) : '';
        if ($patente === '') {
            return null;
        }

        return Poliza::query()
            ->whereHas('risk', fn ($r) => $r->where('metadata->patente', $patente))
            ->orderByRaw("array_position(array['vigente','emitida','vencida','anulada']::text[], estado)")
            ->latest('vigencia')
            ->first();
    }

    /**
     * Estado inicial: el que elige el admin, o inferido de las fechas. Comparación por
     * FECHA (no instante), así el día del vencimiento la póliza sigue vigente:
     *
     * - sin `vigencia_hasta`      → Emitida (fecha de fin desconocida)
     * - `vigencia_desde` futura   → Emitida (emitida pero aún no rige)
     * - `vigencia_hasta` < hoy    → Vencida
     * - si no                     → Vigente
     *
     * @param  array<string, mixed>  $overrides
     */
    private function resolveEstado(array $overrides, IngestedDocument $doc): PolizaEstado
    {
        $override = $overrides['estado'] ?? null;
        if (is_string($override) && $override !== '') {
            return PolizaEstado::from($override);
        }

        $hasta = $this->dateOrNull($this->pick($overrides, 'vigencia_hasta', data_get($doc->payload, 'fechas.vigencia_hasta')));
        if (! $hasta instanceof Carbon) {
            return PolizaEstado::Emitida;
        }

        $desde = $this->dateOrNull($this->pick($overrides, 'vigencia_desde', data_get($doc->payload, 'fechas.vigencia_desde')));
        if ($desde instanceof Carbon && $desde->startOfDay()->greaterThan(Carbon::today())) {
            return PolizaEstado::Emitida;
        }

        return $hasta->startOfDay()->lessThan(Carbon::today()) ? PolizaEstado::Vencida : PolizaEstado::Vigente;
    }

    private function assertNoOtherVigente(int $riskId, PolizaEstado $estado): void
    {
        if ($estado !== PolizaEstado::Vigente) {
            return;
        }

        $exists = Poliza::where('risk_id', $riskId)
            ->where('estado', PolizaEstado::Vigente)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'estado' => 'Este vehículo ya tiene una póliza vigente. Marcá esta como emitida/vencida o resolvé la vigente actual.',
            ]);
        }
    }

    /**
     * Toma el override si vino con valor; si no, el valor estacionado. Normaliza vacío → null.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function pick(array $overrides, string $key, mixed $fallback): ?string
    {
        $value = array_key_exists($key, $overrides) ? $overrides[$key] : $fallback;
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
