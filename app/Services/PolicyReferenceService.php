<?php

namespace App\Services;

use App\Enums\PolizaEstado;
use App\Enums\RiskType;
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
 * `Risk` (find-or-create por patente) y al `Quote`. NO persiste el `presale_id`: es un
 * dato de Visred acotado al ciclo de emisión, no sale del adapter. No conoce a Visred
 * ni al canal: recibe el resultado neutro de la emisión y modelos de dominio.
 */
class PolicyReferenceService
{
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
     * Un auto asegurado N veces (aunque cambie de compañía) es UN `Risk`
     * (doc 10 §7): dedup por (`customer_id`, `type=vehicle`, patente). Sin patente
     * no se puede deduplicar con seguridad → se crea uno nuevo.
     */
    private function findOrCreateRisk(Quote $quote): Risk
    {
        $snapshot = $quote->riskSnapshot;
        $customerId = $snapshot->customer_id;
        $patente = $snapshot->vehicle->patente; // vehicle() usa withDefault() → nunca null

        if ($patente !== null && $patente !== '') {
            $existing = Risk::query()
                ->where('customer_id', $customerId)
                ->where('type', RiskType::Vehicle)
                ->where('metadata->patente', $patente)
                ->first();

            if ($existing instanceof Risk) {
                return $existing;
            }
        }

        return Risk::create([
            'customer_id' => $customerId,
            'type' => RiskType::Vehicle,
            'label' => trim(($snapshot->marca ?? '').' '.($snapshot->modelo ?? '')).($patente ? " ({$patente})" : ''),
            'metadata' => array_filter([
                'patente' => $patente,
                'marca' => $snapshot->marca,
                'modelo' => $snapshot->modelo,
                'version' => $snapshot->version,
                'year' => $snapshot->year,
                'combustible' => $snapshot->combustible,
                'uso' => $snapshot->uso,
                'codigo_postal' => $snapshot->codigo_postal,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ]);
    }
}
