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
            'quote_id'    => $quote->id,
            'aseguradora' => $quote->alternatives()->find($quote->checkout_alternative_id)?->aseguradora,
        ]);

        return ['status' => 'pending_api_implementation'];
    }

    // ─── Métodos a implementar ────────────────────────────────────────────────

    /**
     * Construye el payload para la API de emisión.
     * Incluye datos del quote, snapshot del vehículo, tomador y tarjeta.
     */
    private function buildPayload(Quote $quote, CheckoutSession $session): array
    {
        $snap        = $quote->riskSnapshot;
        $alternative = $quote->alternatives()->find($quote->checkout_alternative_id);

        return [
            // Identificación interna
            'quote_id'    => $quote->id,
            'referencia'  => $quote->external_ref_id,

            // Cobertura seleccionada
            'aseguradora'    => $alternative->aseguradora,
            'plan_codigo'    => $alternative->id,        // TODO: mapear al código real de la aseguradora
            'precio_mensual' => $alternative->precio,
            'moneda'         => $alternative->moneda,

            // Datos del tomador
            'tomador' => [
                'nombre'   => $session->nombre,
                'dni'      => $session->dni,
                'email'    => $session->email,
                'telefono' => $session->telefono,
                'domicilio' => [
                    'calle'     => $session->domicilio_calle,
                    'numero'    => $session->domicilio_numero,
                    'cp'        => $session->domicilio_cp,
                    'provincia' => $session->domicilio_provincia,
                    'localidad' => $session->domicilio_localidad,
                ],
            ],

            // Datos del vehículo (del snapshot inmutable)
            'vehiculo' => [
                'patente'    => $snap->vehicle->patente ?? null,
                'marca'      => $snap->marca,
                'modelo'     => $snap->modelo,
                'version'    => $snap->version,
                'year'       => $snap->year,
                'combustible' => $snap->combustible,
                'uso'        => $session->vehiculo_uso,
                'nro_chasis' => $session->vehiculo_nro_chasis,
                'nro_motor'  => $session->vehiculo_nro_motor,
            ],

            // Datos de pago (desencriptados en el momento justo)
            'pago' => [
                'marca_tarjeta' => $session->cc_brand,
                'pan'           => $session->cc_pan,           // accessor desencripta
                'vencimiento'   => $session->cc_expiry,        // accessor desencripta
                'titular'       => $session->cc_holder_name,   // accessor desencripta
                'dni_titular'   => $session->cc_holder_dni,    // accessor desencripta
            ],
        ];
    }

    /**
     * Realiza el HTTP call a la API externa.
     */
    private function callApi(string $method, string $endpoint, array $payload): array
    {
        $baseUrl = config('services.poliza_api.base_url');
        $apiKey  = config('services.poliza_api.key');
        $timeout = config('services.poliza_api.timeout', 30);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Accept'        => 'application/json',
        ])
        ->timeout($timeout)
        ->{strtolower($method)}("{$baseUrl}{$endpoint}", $payload);

        if ($response->failed()) {
            throw new \Exception(
                "PolizaAPI error {$response->status()}: " . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * Persiste el resultado de la emisión en la base de datos.
     * TODO: crear tabla/modelo PolizaEmitida cuando la API esté definida.
     */
    private function persistResult(Quote $quote, array $apiResponse): void
    {
        // TODO: guardar número de póliza, vigencia, PDF URL, etc.
        // $quote->update(['status' => 'poliza_emitida']);
        // PolizaEmitida::create([...])
    }
}