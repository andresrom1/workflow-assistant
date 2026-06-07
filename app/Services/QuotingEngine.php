<?php

namespace App\Services;

use App\Contracts\QuotationProvider;
use App\Models\RiskSnapshot;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\Log;

class QuotingEngine implements QuotationProvider
{
    use ConditionalLogger;

    /**
     * El Cerebro: Recibe un riesgo y devuelve opciones.
     * NO escribe en base de datos. Solo calcula/obtiene.
     *
     * @return array{
     *     task_id: string,
     *     status: string,
     *     raw: array<string, mixed>,
     *     parsed_alternatives: list<array<string, mixed>>
     * }
     */
    public function generateAlternatives(RiskSnapshot $snapshot): array
    {
        Log::info(__METHOD__.__LINE__." Generating alternatives for RiskSnapshot ID: {$snapshot->id}");
        // 1. Orquestación de fuentes (APIs, Tablas internas, Mocks)
        // En el futuro: $results = $this->apiClient->fetch($snapshot);

        // --- SIMULACIÓN DE LATENCIA ---
        $this->logQuote('Simulando latencia');
        sleep(30);

        // POR AHORA: Usamos el generador mock interno
        return $this->runMockSimulation($snapshot);
    }

    /**
     * Simulación determinística de mercado.
     * Catálogo por compañía — nombres de producto reales de cada manual.
     * Los company slugs (Str::slug del nombre) deben coincidir con coverage_documents.company_slug.
     *
     * @return array{
     *     task_id: string,
     *     status: string,
     *     raw: array<string, mixed>,
     *     parsed_alternatives: list<array<string, mixed>>
     * }
     */
    private function runMockSimulation(RiskSnapshot $snapshot): array
    {
        $taskId = uniqid('task_');
        $alternatives = [];
        $yearFactor = ($snapshot->year > 2020) ? 1.2 : 1.0;

        foreach ($this->catalog() as $company) {
            foreach ($company['plans'] as $plan) {
                $price = ($plan['base'] * $company['factor'] * $yearFactor) + random_int(100, 999);

                $alternatives[] = [
                    'external_code' => uniqid('sku_'),
                    'external_quote_id' => uniqid('qid_'),
                    'aseguradora' => $company['name'],
                    'descripcion' => "{$plan['code']} - ".implode(', ', array_slice($plan['feats'], 0, 2)),
                    'titulo' => $plan['code'],
                    'normalized_grade' => $plan['grade'],
                    'precio' => round($price, 2),
                    'moneda' => 'ARS',
                    'marketing_title' => "{$company['name']} - {$plan['code']}",
                    'sum_insured_text' => '$ 15.000.000',
                    'features_tags' => $plan['feats'],
                    'full_details' => $plan['details'],
                ];
            }
        }

        Log::info('Alternatives', ['alternatives' => $alternatives]);

        return [
            'task_id' => $taskId,
            'status' => 'SUCCESS',
            'raw' => ['source' => 'QuotingEngine Mock', 'snapshot_id' => $snapshot->id],
            'parsed_alternatives' => $alternatives,
        ];
    }

    /**
     * Catálogo de productos reales por compañía.
     * Nombres extraídos de los manuales oficiales de cada aseguradora.
     * El slug generado por Str::slug(name) debe coincidir con coverage_documents.company_slug.
     *
     * Triunfo        → slug: "triunfo"
     * San Cristobal  → slug: "san-cristobal"
     * Sancor         → slug: "sancor"
     * Rio Uruguay    → slug: "rio-uruguay"
     *
     * @return array<int, array{name: string, factor: float, plans: array}>
     */
    private function catalog(): array
    {
        return [

            // ─────────────────────────────────────────────────────────────
            // TRIUNFO SEGUROS — manual marzo 2026
            // Ruedas (autos/pick-ups A): C/C1 = 1/evento; C2/C8 = 1/evento 2/vigencia;
            //   C2 FULL = 2/evento 2/vigencia; D y variantes = 2/evento 2/vigencia
            // ─────────────────────────────────────────────────────────────
            [
                'name' => 'Triunfo',
                'factor' => 1.00,
                'plans' => [
                    [
                        'code' => 'A',
                        'grade' => 'liability',
                        'base' => 10000,
                        'feats' => ['Responsabilidad Civil'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido. Límite según categoría del vehículo.',
                        ],
                    ],
                    [
                        'code' => 'B0',
                        'grade' => 'basic',
                        'base' => 15000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total' => 'Incluido. Facultativo según zona.',
                        ],
                    ],
                    [
                        'code' => 'B1',
                        'grade' => 'basic',
                        'base' => 18000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'B3',
                        'grade' => 'basic',
                        'base' => 21000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'B4',
                        'grade' => 'basic',
                        'base' => 23000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Daños Parciales por Robo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Daños Parciales por Robo' => 'Incluido hasta suma asegurada.',
                        ],
                    ],
                    [
                        'code' => 'B',
                        'grade' => 'basic',
                        'base' => 25000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'C',
                        'grade' => 'third_party_complete',
                        'base' => 29000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Ruedas' => 'Reposición a valor de fábrica. 1 rueda por evento.',
                        ],
                    ],
                    [
                        'code' => 'C1',
                        'grade' => 'third_party_complete',
                        'base' => 32000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Ruedas' => 'Reposición a valor de fábrica. 1 rueda por evento.',
                        ],
                    ],
                    [
                        'code' => 'C2',
                        'grade' => 'third_party_complete',
                        'base' => 36000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido hasta suma asegurada.',
                            'Ruedas' => 'Reposición a valor de fábrica. 1 rueda por evento, 2 eventos por vigencia.',
                        ],
                    ],
                    [
                        'code' => 'C8',
                        'grade' => 'third_party_complete',
                        'base' => 40000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido hasta suma asegurada.',
                            'Ruedas' => 'Reposición a valor de fábrica. 1 rueda por evento, 2 eventos por vigencia.',
                        ],
                    ],
                    [
                        'code' => 'C2 FULL',
                        'grade' => 'third_party_complete',
                        'base' => 46000,
                        'feats' => ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales de Techo', 'Inundación o Desbordamiento', 'Ruedas'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo o Hurto Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada del vehículo.',
                            'Cristales de Techo' => 'Incluido hasta suma asegurada.',
                            'Inundación o Desbordamiento' => 'Incluido hasta suma asegurada del vehículo.',
                            'Ruedas' => 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
                        ],
                    ],
                    [
                        'code' => 'D',
                        'grade' => 'all_risk',
                        'base' => 65000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo Sin Franquicia', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente. Sin franquicia.',
                            'Cristales' => 'Incluido sin deducible.',
                            'Cerraduras' => 'Incluido sin deducible.',
                            'Granizo' => 'Incluido sin deducible.',
                            'Ruedas' => 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
                        ],
                    ],
                    [
                        'code' => 'D2',
                        'grade' => 'all_risk',
                        'base' => 78000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Franquicia Fija', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Franquicia fija a cargo del asegurado en siniestros culpables.',
                            'Cristales' => 'Incluido sin deducible.',
                            'Cerraduras' => 'Incluido sin deducible.',
                            'Granizo' => 'Incluido sin deducible.',
                            'Ruedas' => 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
                        ],
                    ],
                    [
                        'code' => 'D3',
                        'grade' => 'all_risk',
                        'base' => 85000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Franquicia Variable', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Franquicia variable según porcentaje de suma asegurada.',
                            'Cristales' => 'Incluido sin deducible.',
                            'Cerraduras' => 'Incluido sin deducible.',
                            'Granizo' => 'Incluido sin deducible.',
                            'Ruedas' => 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
                        ],
                    ],
                    [
                        'code' => 'D4',
                        'grade' => 'all_risk',
                        'base' => 95000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Franquicia 5%', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Franquicia del 5% de la suma asegurada.',
                            'Cristales' => 'Incluido sin deducible.',
                            'Cerraduras' => 'Incluido sin deducible.',
                            'Granizo' => 'Incluido sin deducible.',
                            'Ruedas' => 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
                        ],
                    ],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // SAN CRISTOBAL SEGUROS — insert junio 2025
            // ─────────────────────────────────────────────────────────────
            [
                'name' => 'San Cristobal',
                'factor' => 1.05,
                'plans' => [
                    [
                        'code' => 'AUTO BASE',
                        'grade' => 'liability',
                        'base' => 11000,
                        'feats' => ['Responsabilidad Civil'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'AUTO MIX',
                        'grade' => 'basic',
                        'base' => 20000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total', 'Incendio Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total' => 'Incluido.',
                            'Incendio Total' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'AUTO PLUS',
                        'grade' => 'third_party_complete',
                        'base' => 32000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'AUTO PLUS MAS',
                        'grade' => 'third_party_complete',
                        'base' => 42000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'AUTO MEGA',
                        'grade' => 'third_party_complete',
                        'base' => 52000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                            'Ruedas' => 'Incluido. Consultar condiciones de póliza para límite de eventos.',
                            'Cerraduras' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'AUTO EXTRA C/FRANQUICIA',
                        'grade' => 'all_risk',
                        'base' => 72000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Franquicia Variable', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Franquicia variable sobre suma asegurada. Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // SANCOR SEGUROS — manual de productos
            // ─────────────────────────────────────────────────────────────
            [
                'name' => 'Sancor',
                'factor' => 1.20,
                'plans' => [
                    [
                        'code' => 'Auto Max 1',
                        'grade' => 'liability',
                        'base' => 12000,
                        'feats' => ['Responsabilidad Civil'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Auto Max 13',
                        'grade' => 'basic',
                        'base' => 21000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total', 'Incendio Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total' => 'Incluido.',
                            'Incendio Total' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Auto Max 3',
                        'grade' => 'third_party_complete',
                        'base' => 34000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Auto Max 6',
                        'grade' => 'third_party_complete',
                        'base' => 44000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Premium Max',
                        'grade' => 'third_party_complete',
                        'base' => 50000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                            'Ruedas' => 'Incluido. Consultar condiciones de póliza para límite de eventos.',
                            'Cerraduras' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Auto Max Todo Riesgo',
                        'grade' => 'all_risk',
                        'base' => 75000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // RIO URUGUAY SEGUROS (RUS) — manual de productos
            // ─────────────────────────────────────────────────────────────
            [
                'name' => 'Rio Uruguay',
                'factor' => 0.95,
                'plans' => [
                    [
                        'code' => 'B',
                        'grade' => 'basic',
                        'base' => 14000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total', 'Incendio Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total' => 'Incluido.',
                            'Incendio Total' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'B1',
                        'grade' => 'basic',
                        'base' => 16000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'B2',
                        'grade' => 'basic',
                        'base' => 18000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'B3',
                        'grade' => 'basic',
                        'base' => 20000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Daños Parciales por Robo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Daños Parciales por Robo' => 'Incluido hasta suma asegurada.',
                        ],
                    ],
                    [
                        'code' => 'B4 - Robo Plus',
                        'grade' => 'basic',
                        'base' => 22000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Daños Parciales por Robo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido con cobertura ampliada.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Daños Parciales por Robo' => 'Incluido hasta suma asegurada.',
                        ],
                    ],
                    [
                        'code' => 'Sigma Cero',
                        'grade' => 'third_party_complete',
                        'base' => 30000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Sigma',
                        'grade' => 'third_party_complete',
                        'base' => 38000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'Sigma Importado',
                        'grade' => 'third_party_complete',
                        'base' => 42000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'C3-80',
                        'grade' => 'third_party_complete',
                        'base' => 46000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                            'Ruedas' => 'Incluido. Consultar condiciones de póliza para límite de eventos.',
                        ],
                    ],
                    [
                        'code' => 'Robo Plus',
                        'grade' => 'third_party_complete',
                        'base' => 50000,
                        'feats' => ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Robo Total y Parcial' => 'Incluido con cobertura ampliada.',
                            'Incendio Total y Parcial' => 'Incluido.',
                            'Destrucción Total por Accidente' => 'Incluido.',
                            'Granizo' => 'Cubierto hasta suma asegurada.',
                            'Cristales' => 'Incluido.',
                            'Ruedas' => 'Incluido. Consultar condiciones de póliza para límite de eventos.',
                            'Cerraduras' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T44',
                        'grade' => 'all_risk',
                        'base' => 58000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T43',
                        'grade' => 'all_risk',
                        'base' => 64000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T31',
                        'grade' => 'all_risk',
                        'base' => 68000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T32',
                        'grade' => 'all_risk',
                        'base' => 74000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T34',
                        'grade' => 'all_risk',
                        'base' => 80000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                    [
                        'code' => 'T38',
                        'grade' => 'all_risk',
                        'base' => 88000,
                        'feats' => ['Responsabilidad Civil', 'Todo Riesgo', 'Cristales', 'Cerraduras', 'Granizo'],
                        'details' => [
                            'Responsabilidad Civil' => 'Incluido.',
                            'Todo Riesgo' => 'Daño Total y Parcial por Accidente.',
                            'Franquicia' => 'Ver condiciones de póliza.',
                            'Cristales' => 'Incluido.',
                            'Cerraduras' => 'Incluido.',
                            'Granizo' => 'Incluido.',
                        ],
                    ],
                ],
            ],

        ];
    }
}
