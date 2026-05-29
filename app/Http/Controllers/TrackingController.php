<?php

namespace App\Http\Controllers;

use App\Models\EmergencyTrackingToken;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint público para que los contactos vean la ubicación compartida.
 *
 * Spec v2 §4.3: URL del tracking = mango.broker/track/{token}. Sin auth,
 * se accede desde el link de WhatsApp o Share nativo. El token es la
 * autorización.
 *
 * Devuelve JSON para el mock — el HTML con mapa real es trabajo de UI.
 * Estados:
 *   - active=true → last_lat, last_lon, last_updated_at
 *   - active=false → mensaje "ya no está disponible"
 */
class TrackingController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $row = EmergencyTrackingToken::where('token', $token)->first();

        if (! $row || ! $row->isActive()) {
            return response()->json([
                'active' => false,
                'message' => 'Esta ubicación ya no está disponible.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'active' => true,
            'last_lat' => $row->last_lat,
            'last_lon' => $row->last_lon,
            'last_updated_at' => $row->last_updated_at?->toIso8601String(),
            'expires_at' => $row->expires_at->toIso8601String(),
        ]);
    }
}
