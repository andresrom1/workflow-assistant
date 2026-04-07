<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de emisión de póliza contra la API externa.
 *
 * ═══════════════════════════════════════════════════════════
 *  SKELETON — Implementar cuando la API esté disponible
 * ═══════════════════════════════════════════════════════════
 *
 * Responsabilidades:
 *   1. Construir el payload con datos del quote + snapshot + checkout
 *   2. Llamar al endpoint de la API
 *   3. Parsear la respuesta y persistir la póliza emitida
 *   4. Actualizar el status del quote a 'poliza_emitida'
 *
 * Configuración esperada en config/services.php:
 *   'poliza_api' => [
 *       'base_url' => env('POLIZA_API_BASE_URL'),
 *       'key'      => env('POLIZA_API_KEY'),
 *       'timeout'  => env('POLIZA_API_TIMEOUT', 30),
 *   ]
 *
 * Variables .env requeridas:
 *   POLIZA_API_BASE_URL=https://api.aseguradora.com/v1
 *   POLIZA_API_KEY=secret
 *   POLIZA_API_TIMEOUT=30
 */
class PolizaEmisionService
{
    /**
     * Emite la póliza para un checkout completado.
     *
     * @return array Respuesta de la API (número de póliza, vigencia, etc.)
     *
     * @throws \Exception Si la API falla o devuelve error
     */
    public function emitir(Quote $quote, CheckoutSession $session): array
    {
        // ── TODO: Descomentar cuando la API esté disponible ──────────────────

        // $payload = $this->buildPayload($quote, $session);
        // $response = $this->callApi('POST', '/polizas/emitir', $payload);
        // $this->persistResult($quote, $response);
        // return $response;

        // ── Skeleton activo: solo loguea ─────────────────────────────────────

        Log::info('PolizaEmisionService: API no implementada aún — skeleton activo', [
            'quote_id' => $quote->id,
            'aseguradora' => $quote->alternatives()->find($quote->checkout_alternative_id)?->aseguradora,
        ]);

        return ['status' => 'pending_api_implementation'];
    }
}
