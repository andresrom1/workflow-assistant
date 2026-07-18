<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/**
 * Materializa la referencia de póliza en cartera tras emitir (doc 10 §4/§5/§7).
 *
 * Dominio CARTERA, separado del acto de emitir: la compañía es el System of Record
 * (estado/cobertura/endosos/documentos viven allá, on-demand); MANGO guarda solo la
 * referencia mínima durable (`policy_number` + `company_id`/`product_id`) ligada a un
 * `Risk` (find-or-create vía {@see PolicyChainResolver} — mismo dedup que ingesta/reporte/
 * alta manual, llaveado por `natural_key` del InsurableAsset) y al `Quote`. NO persiste el
 * `presale_id`: es un dato de Visred acotado al ciclo de emisión, no sale del adapter. No
 * conoce a Visred ni al canal: recibe el resultado neutro de la emisión y modelos de dominio.
 */
class PolicyReferenceService
{
    public function __construct(
        private readonly PolicyChainResolver $chain,
    ) {}

    /**
     * Liga la emisión a la cartera: find-or-create del `Risk` (dedup por patente)
     * + upsert de la `Poliza`-referencia por `quote_id` (idempotente).
     *
     * @param  array<string, mixed>  $emissionResult  Shape neutro de EmissionProvider::emit.
     */
    public function materialize(Quote $quote, QuoteAlternative $alternative, array $emissionResult): Poliza
    {
        return DB::transaction(function () use ($quote, $alternative, $emissionResult): Poliza {
            $risk = $this->findOrCreateRisk($quote);

            return Poliza::updateOrCreate(
                ['quote_id' => $quote->id],
                [
                    'risk_id' => $risk->id,
                    // Referencia durable al System of Record: el número de póliza (el
                    // `presale_id` de Visred no se persiste — muere con la emisión).
                    'numero' => $emissionResult['policy_number'] ?? null,
                    'company_id' => $emissionResult['company_id'] ?? null,
                    'product_id' => 'auto',
                    // Display que MANGO ya conoce (de la alternativa elegida). Visred no
                    // expone un endpoint de cartera para re-consultar el contrato, así que
                    // congelamos acá lo cotizado: es la única fuente de estos campos.
                    'estado' => PolizaEstado::Vigente,
                    'company' => $alternative->aseguradora,
                    'coverage' => $alternative->titulo,
                    'coverage_detail' => $alternative->descripcion,
                    'sum_asegurada' => $alternative->sum_asegurada,
                    'cuota' => $alternative->precio,
                    'emitida_en' => now(),
                    // Seed de vigencia (emisión + 1 año): la compañía no expone el término real
                    // y no hay read-path. Da una fecha tentativa para la detección de vencimientos
                    // (Fase 3); la carga manual del documento la corrige si difiere.
                    'vigencia' => now()->addYear(),
                    'last_synced_at' => now(),
                    // Extras de la emisión (no son columnas de referencia): permiten
                    // reconstruir el resultado neutro sin volver a emitir (idempotencia).
                    'metadata' => [
                        'proposal_number' => $emissionResult['proposal_number'] ?? null,
                        'emission_status' => $emissionResult['emission_status'] ?? null,
                        'requires_inspection_after_emission' => $emissionResult['requires_inspection_after_emission'] ?? false,
                    ],
                ],
            );
        });
    }

    /**
     * Un auto asegurado N veces (aunque cambie de compañía) es UN `Risk` (doc 10 §7):
     * dedup por (`customer_id`, `type=vehicle`, `natural_key`=patente normalizada),
     * delegado en {@see PolicyChainResolver}. Sin patente no se puede deduplicar con
     * seguridad → se crea un asset/risk nuevo.
     */
    private function findOrCreateRisk(Quote $quote): Risk
    {
        $snapshot = $quote->riskSnapshot;
        // withTrashed: la emisión no debe romperse si el cliente quedó soft-borrado
        // (el borrado solo se bloquea si hay póliza vigente, no durante la cotización).
        $customer = Customer::withTrashed()->findOrFail($snapshot->customer_id);

        return $this->chain->resolveRisk($customer, AssetType::Vehicle, [
            'patente' => $snapshot->vehicle->patente, // vehicle() usa withDefault() → nunca null
            'marca' => $snapshot->marca,
            'modelo' => $snapshot->modelo,
            'version' => $snapshot->version,
            'year' => $snapshot->year,
            'combustible' => $snapshot->combustible,
            'uso' => $snapshot->uso,
            'codigo_postal' => $snapshot->codigo_postal,
        ]);
    }
}
