<?php

namespace App\Http\Controllers\Api;

use App\Events\QuoteProcessed;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Repositories\QuoteRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QuoteWebhookController extends Controller
{
    public function __construct(
        private readonly QuoteRepository $quoteRepo
    ) {}

    /**
     * Recibe los resultados de una cotización manual realizada por un PAS.
     */
    public function handle(Request $request)
    {
        $data = $request->validate([
            'quote_id' => 'required|exists:quotes,id',
            'opportunity_id' => 'required|string',
            'status' => 'required|string', // 'resolved', 'rejected'
            'alternatives' => 'nullable|array',
            'alternatives.*.aseguradora' => 'required|string',
            'alternatives.*.precio' => 'required|numeric',
            'alternatives.*.titulo' => 'required|string',
            'alternatives.*.normalized_grade' => 'nullable|string',
            // ✅ agregar estos:
            'alternatives.*.descripcion' => 'nullable|string',
            'alternatives.*.marketing_title' => 'nullable|string',
            'alternatives.*.sum_insured_text' => 'nullable|string',
            'alternatives.*.features_tags' => 'nullable|array',
            'alternatives.*.full_details' => 'nullable|array',
        ]);

        // 0. Perform normalization if missing (it's now responsibility of the AI Agent)
        if (! empty($data['alternatives'])) {
            foreach ($data['alternatives'] as &$alt) {
                if (empty($alt['normalized_grade'])) {
                    $alt['normalized_grade'] = match (strtolower($alt['titulo'] ?? '')) {
                        'responsabilidad civil', 'rc', 'a' => 'liability',
                        'terceros completo', 'c', 'cplus', 'c8' => 'third_party_complete',
                        'todo riesgo', 'd', 'd1', 'd2', 'tr' => 'all_risk',
                        default => 'basic',
                    };
                }

                // Generate external_code if missing (required for quote_alternatives table)
                if (empty($alt['external_code'])) {
                    $alt['external_code'] = uniqid('sku_');
                }

                // Add external_quote_id (required for quote_alternatives table)
                if (empty($alt['external_quote_id'])) {
                    $alt['external_quote_id'] = $data['opportunity_id'];
                }
            }
        }

        $quote = Quote::findOrFail($data['quote_id']);

        Log::info("[Webhook] Recibida respuesta para Quote #{$quote->id} (Status: {$data['status']})");

        // 1. Validar que la cotización esté en un estado válido para recibir respuesta
        if ($quote->status !== 'offered_pas') {
            Log::warning("[Webhook] Quote #{$quote->id} no está en estado 'offered_pas' (Actual: {$quote->status}). Ignorando.");

            return response()->json(['message' => 'Quote no longer accepting manual results'], 422);
        }

        // 2. Manejar el estado de la respuesta
        if ($data['status'] === 'rejected') {
            $quote->update(['status' => 'rejected_pas']);

            $quote->mobileSyncLogs()->create([
                'status' => 'webhook_received',
                'response_data' => $data,
                'synced_at' => now(),
            ]);

            Log::info("[Webhook] Quote #{$quote->id} rechazada por PAS.");

            // Aquí podríamos disparar el fallback a API inmediatamente si lo deseamos
            // o dejar que el Timeout Job lo haga. Por seguridad, lo disparamos ahora.
            // Para simplicidad en este MVP, dejaremos que el Timeout Job lo maneje
            // a menos que queramos máxima velocidad.

            return response()->json(['message' => 'Rejection received']);
        }

        // 3. Procesar alternativas si es 'resolved'
        if ($data['status'] === 'resolved' && ! empty($data['alternatives'])) {

            // Mapeamos al formato esperado por el Repo
            $result = [
                'status' => 'SUCCESS',
                'parsed_alternatives' => $data['alternatives'],
                'raw' => ['source' => 'mobile_app', 'opportunity_id' => $data['opportunity_id']],
            ];

            // Guardamos usando el repo existente
            $this->quoteRepo->saveResults($quote, $result);

            // 4. Registrar la sincronización
            $quote->mobileSyncLogs()->create([
                'opportunity_id' => $data['opportunity_id'],
                'status' => 'webhook_received',
                'response_data' => $data,
                'synced_at' => now(),
            ]);

            // 5. Notificar
            QuoteProcessed::dispatch($quote);

            Log::info("[Webhook] Quote #{$quote->id} procesada exitosamente vía PAS.");

            return response()->json(['message' => 'Quote resolved successfully']);
        }

        return response()->json(['message' => 'Invalid status or missing alternatives'], 400);
    }
}
