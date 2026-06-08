<?php

namespace App\Contracts;

use App\Models\RiskSnapshot;

/**
 * Puerto de cotización — agnóstico de proveedor.
 *
 * El dominio (ApiQuoteResolution) depende de esta interface, NO de un proveedor
 * concreto. Implementación de producción: VisredQuotationProvider (real siempre,
 * bind directo en AppServiceProvider). En tests: StubQuotationProvider. El
 * contrato de retorno es la shape neutra de MANGO que consume
 * QuoteRepository::saveResults() — nunca un DTO de Visred.
 *
 * Ver docs/v2/10-modelo-dominio-cotizacion-emision.md.
 */
interface QuotationProvider
{
    /**
     * Genera las alternativas de cotización para un snapshot de riesgo.
     * NO escribe en base de datos: solo calcula/obtiene.
     *
     * @return array{
     *     task_id: string,
     *     status: string,
     *     raw: array<string, mixed>,
     *     parsed_alternatives: list<array<string, mixed>>
     * }
     */
    public function generateAlternatives(RiskSnapshot $snapshot): array;
}
