<?php

namespace Tests\Support;

use App\Contracts\QuotationProvider;
use App\Models\RiskSnapshot;

/**
 * Doble de test del puerto {@see QuotationProvider}.
 *
 * Devuelve alternativas determinísticas sin tocar la red, para que los tests que
 * NO son de cotización Visred (flujo del orquestador, CRUD) no peguen a la API.
 * Los tests del path real usan VisredQuotationProvider con Http::fake.
 *
 * Espeja {@see StubQuotability}: en producción corre el proveedor real siempre;
 * el stub vive solo en tests (bind por defecto en TestCase).
 */
class StubQuotationProvider implements QuotationProvider
{
    public function generateAlternatives(RiskSnapshot $snapshot): array
    {
        return [
            'task_id' => 'stub-task',
            'status' => 'SUCCESS',
            'raw' => ['source' => 'StubQuotationProvider'],
            'parsed_alternatives' => [
                [
                    'external_code' => 'STUB-RC',
                    'external_quote_id' => 'stub-1',
                    'aseguradora' => 'Stub Seguros',
                    'titulo' => 'Responsabilidad Civil',
                    'descripcion' => 'Cobertura de prueba (stub).',
                    'normalized_grade' => 'liability',
                    'precio' => 10000.0,
                    'moneda' => 'ARS',
                    'marketing_title' => 'Stub Seguros - Responsabilidad Civil',
                    'sum_insured_text' => '',
                    'features_tags' => ['Responsabilidad Civil'],
                    'full_details' => ['Responsabilidad Civil' => 'Incluido.'],
                ],
            ],
        ];
    }
}
