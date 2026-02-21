<?php

namespace App\Services\Quote\Strategies;

use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\Quote\QuoteResolutionStrategyInterface;
use App\Events\QuoteOfferedToPas;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileAppQuoteResolution implements QuoteResolutionStrategyInterface
{
    public function resolve(Quote $quote, RiskSnapshot $snapshot): void
    {

        $endpoint = config('services.mobile_app.endpoint') ?: 'http://127.0.0.1:8000/api/webhooks/opportunity';

        if (empty($endpoint)) {
            throw new \Exception("The Mobile App endpoint is not configured in services.php or .env");
        }

        Log::info(__METHOD__ . " Ofreciendo Quote ID: {$quote->id} a PAS vía Mobile App en {$endpoint}");

        // 1. Preparar Payload (Simulado)
        $timeout = config('services.mobile_app.timeout_minutes', 30);
        $httpTimeout = config('services.mobile_app.http_timeout', 10);

        $payload = [
            'quote_id' => $quote->id,
            'snapshot' => $snapshot->toArray(),
            'coverage_preference' => $snapshot->coverage_preference,
            'expires_at' => now()->addMinutes($timeout)->toIso8601String(),
        ];

        // 2. Llamada a la API externa
        Log::info("[MobileAppQuoteResolution] Enviando a PAS en {$endpoint}...", ['payload' => $payload]);

        try {
            $response = Http::timeout($httpTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                throw new \Exception("Error al enviar oportunidad a PAS (HTTP {$response->status()}): {$response->body()}");
            }

            $responseData = $response->json();
            $opportunityId = $responseData['opportunity_id'] ?? ('opp_' . uniqid());

            // 3. Actualizar Quote
            $quote->update([
                'status' => 'offered_pas',
                'resolution_method' => 'mobile',
                'mobile_opportunity_id' => $opportunityId,
                'sent_to_mobile_at' => now(),
                'expected_resolution_at' => now()->addMinutes($timeout),
            ]);

            // 4. Registrar en el Log de Sincronización
            $quote->mobileSyncLogs()->create([
                'opportunity_id' => $opportunityId,
                'status' => 'success',
                'response_data' => $responseData,
                'synced_at' => now(),
            ]);

            // 5. Disparar Evento para el Observer
            QuoteOfferedToPas::dispatch($quote);

        } catch (Throwable $e) {
            Log::error("[MobileAppQuoteResolution] Fallo al ofrecer a PAS: " . $e->getMessage());

            $quote->update(['status' => 'failed']);

            $quote->mobileSyncLogs()->create([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'synced_at' => now(),
            ]);

            throw $e;
        }
    }

    public function canHandle(Quote $quote, RiskSnapshot $snapshot): bool
    {
        // Podríamos filtrar por complejidad, por ejemplo.
        return true;
    }

    public function getName(): string
    {
        return 'mobile';
    }
}
