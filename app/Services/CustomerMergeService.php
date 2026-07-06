<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\CustomerRepository;
use App\Support\DocumentoIdentidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fusiona dos `Customer` que resultan ser la misma persona en una sola fila canónica.
 *
 * Es **agnóstico de canal**: depende solo del modelo de dominio. Resuelve el problema
 * estructural de que cada puerta de entrada crea un customer atado a una sola clave
 * (WhatsApp → teléfono, app mobile → email, checkout → email+DNI) y nunca se cruzan
 * hasta converger. Complementa a {@see CustomerConsolidationService}: ese reconcilia
 * **campos** dentro de una fila; este reconcilia **dos filas** en una.
 *
 * Ver docs/v2/11 y la Bitácora del ROADMAP.
 */
class CustomerMergeService
{
    /**
     * Tablas con columna `customer_id` que deben re-apuntar del perdedor al survivor.
     * `quotes` no figura: cuelga de `conversation_id`, así que viaja con las conversaciones.
     */
    private const FK_TABLES = [
        'conversations', 'vehicles', 'risks',
        'risk_snapshots', 'customer_audits', 'mobile_accounts',
    ];

    public function __construct(
        private readonly CustomerConsolidationService $consolidation,
        private readonly CustomerRepository $customers,
    ) {}

    /**
     * Colapsa en `$survivor` cualquier customer existente que ya posea alguno de los
     * identificadores **fuertes** declarados (DNI/email). Maneja el caso de que el DNI esté
     * en una fila y el email en otra: fusiona ambas. Devuelve el survivor canónico.
     *
     * Reconcilia SOLO por claves fuertes (documento_key, dni, email): un match garantiza, por
     * construcción, la misma identidad. `documento_key` es la clave canónica del documento
     * (colapsa DNI ↔ CUIL/CUIT de la misma persona, ver {@see DocumentoIdentidad}).
     * El teléfono NO es único (un número puede quedar reasignado a otra persona) y queda
     * deliberadamente fuera para no fusionar dos personas distintas. En el checkout dni y email
     * son obligatorios, así que ya cubren todo duplicado real de la misma persona. Ver docs/v2/12 §5.
     *
     * @param  array<string, string|null>  $identifiers  claves: documento_key, dni, email
     */
    public function reconcile(Customer $survivor, array $identifiers): Customer
    {
        /** @var array<int, Customer> $losers */
        $losers = [];

        foreach ($identifiers as $type => $value) {
            if (blank($value)) {
                continue;
            }

            $match = match ($type) {
                'documento_key' => $this->customers->findByDocumentoKey(trim($value)),
                'dni' => $this->customers->findByDni(trim($value)),
                'email' => $this->customers->findByEmail(mb_strtolower(trim($value))),
                default => null,
            };

            if ($match instanceof Customer && $match->id !== $survivor->id) {
                $losers[$match->id] = $match; // dedup por id
            }
        }

        foreach ($losers as $loser) {
            $survivor = $this->merge($survivor, $loser);
        }

        return $survivor;
    }

    /**
     * Fusiona `$loser` dentro de `$survivor`: repunta sus FKs, lo elimina y resuelve los
     * campos con survivorship por campo (ver {@see CustomerConsolidationService::mergeFields()}).
     */
    public function merge(Customer $survivor, Customer $loser): Customer
    {
        if ($survivor->id === $loser->id) {
            return $survivor;
        }

        return DB::transaction(function () use ($survivor, $loser): Customer {
            $loserValues = $this->canonicalFields($loser);
            /** @var array<string, array{source: string, at: string}> $loserSources */
            $loserSources = $loser->metadata['field_sources'] ?? [];

            // Repuntar todas las FKs perdedor → survivor (raw: incluye filas soft-deleted).
            foreach (self::FK_TABLES as $table) {
                DB::table($table)
                    ->where('customer_id', $loser->id)
                    ->update(['customer_id' => $survivor->id]);
            }

            Log::info('CustomerMerge: filas fusionadas', [
                'survivor_id' => $survivor->id,
                'loser_id' => $loser->id,
            ]);

            // Hard delete: el índice único de dni/email NO es parcial, un soft-delete
            // retendría el slot e impediría que el survivor tome esas claves.
            $loser->forceDelete();

            // Survivorship por campo: gana la fuente más confiable (admin > checkout > chat),
            // desempata recencia; preserva provenance y audita cambios y descartes.
            $this->consolidation->mergeFields($survivor, $loserValues, $loserSources);

            return $survivor->refresh();
        });
    }

    /**
     * Lee los campos canónicos del perdedor como array para alimentar la consolidación.
     *
     * @return array<string, mixed>
     */
    private function canonicalFields(Customer $loser): array
    {
        $out = [];
        foreach (CustomerConsolidationService::fields() as $field) {
            $out[$field] = $loser->getAttribute($field);
        }

        return $out;
    }
}
