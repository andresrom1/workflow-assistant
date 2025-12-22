<?php

namespace App\Services;

use App\Models\RiskSnapshot;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Log;

class QuotingEngine
{
    use ConditionalLogger;
    /**
     * El Cerebro: Recibe un riesgo y devuelve opciones.
     * NO escribe en base de datos. Solo calcula/obtiene.
     * @param RiskSnapshot $snapshot
     * @return array Estructura normalizada lista para que el Job la persista.
     */
    public function generateAlternatives(RiskSnapshot $snapshot): array
    {
        Log::info(__METHOD__.__line__." Generating alternatives for RiskSnapshot ID: {$snapshot->id}");
        // 1. Orquestación de fuentes (APIs, Tablas internas, Mocks)
        // En el futuro: $results = $this->apiClient->fetch($snapshot);
        
        // --- SIMULACIÓN DE LATENCIA ---
        $this->logQuote('Simulando latencia' );
        sleep(90);

        // POR AHORA: Usamos el generador mock interno
        return $this->runMockSimulation($snapshot);
    }

    /**
     * Simulación determinística de mercado.
     */
    private function runMockSimulation(RiskSnapshot $snapshot): array
    {
        $taskId = uniqid('task_');
        $alternatives = [];

        // Configuración del Mercado
        $companies = [
            ['name' => 'Triunfo Seguros', 'factor' => 1.00],
            ['name' => 'Sancor Seguros',  'factor' => 1.35],
            ['name' => 'Rivadavia',       'factor' => 1.15],
            ['name' => 'Mercantil Andina','factor' => 1.10],
            ['name' => 'Zurich',          'factor' => 1.60],
        ];

        // Catálogo de Productos (Planes)
        $plans = [
            // Liability
            ['code' => 'A', 'grade' => 'liability', 'base' => 12000, 'feats' => ['Responsabilidad Civil']],
            // Basics
            ['code' => 'B1', 'grade' => 'basic', 'base' => 18000, 'feats' => ['Responsabilidad Civil', 'Robo Total', 'Incendio Total']],
            ['code' => 'B',  'grade' => 'basic', 'base' => 24000, 'feats' => ['RC', 'Robo Total/Parcial', 'Incendio Total/Parcial']],
            // Complete
            ['code' => 'C',  'grade' => 'third_party_complete', 'base' => 32000, 'feats' => ['RC', 'Robo Total/Parcial', 'Incendio Total/Parcial', 'Destrucción Total']],
            ['code' => 'C8', 'grade' => 'third_party_complete', 'base' => 38000, 'feats' => ['RC', 'Robo Total/Parcial', 'Incendio Total/Parcial', 'Destrucción Total', 'Granizo', 'Cristales']],
            ['code' => 'Cfull', 'grade' => 'third_party_complete', 'base' => 45000, 'feats' => ['RC', 'Robo Total/Parcial', 'Incendio Total/Parcial', 'Destrucción Total', 'Granizo Ilimitado', 'Cristales', 'Cerraduras', 'Ruedas']],
            // All Risk
            ['code' => 'D1', 'grade' => 'all_risk', 'base' => 65000, 'feats' => ['Todo Riesgo', 'Franquicia $800.000']],
            ['code' => 'D2', 'grade' => 'all_risk', 'base' => 95000, 'feats' => ['Todo Riesgo', 'Franquicia $250.000']],
        ];

        // Algoritmo de Generación
        foreach ($companies as $company) {
            foreach ($plans as $plan) {
                
                // Fórmula de Precio: (Base * Factor Cía * Factor Año Auto) + Ruido Random
                $yearFactor = ($snapshot->year > 2020) ? 1.2 : 1.0; 
                $price = ($plan['base'] * $company['factor'] * $yearFactor) + rand(100, 999);

                $alternatives[] = [
                    'external_code'     => uniqid('sku_'),
                    'external_quote_id' => uniqid('qid_'),
                    'aseguradora'       => $company['name'],
                    'descripcion'       => "{$plan['code']} - " . implode(', ', array_slice($plan['feats'], 0, 2)),
                    'titulo'            => $plan['code'],
                    'normalized_grade'  => $plan['grade'],
                    'precio'            => round($price, 2),
                    'moneda'            => 'ARS',
                    'marketing_title'   => "{$company['name']} - {$plan['code']}",
                    'sum_insured_text'  => '$ 15.000.000',
                    'features_tags'     => $plan['feats'],
                    'full_details'      => $this->enrichDetails($plan['feats']),
                ];
            }
        }
        
        Log::info("Alternatives", ['alternatives' => $alternatives]);
        return [
            'task_id' => $taskId,
            'status'  => 'SUCCESS',
            'raw'     => ['source' => 'QuotingEngine Mock', 'snapshot_id' => $snapshot->id], 
            'parsed_alternatives' => $alternatives
        ];
    }

    private function enrichDetails(array $features): array
    {
        $details = [];
        foreach ($features as $f) {
            $details[$f] = match(true) {
                str_contains($f, 'Granizo') => "Cubierto hasta suma asegurada.",
                str_contains($f, 'Ruedas') => "Reposición a nuevo, 1 evento anual.",
                str_contains($f, 'Franquicia') => "A cargo del asegurado en siniestros culpables.",
                default => "Incluido en póliza."
            };
        }
        return $details;
    }
}