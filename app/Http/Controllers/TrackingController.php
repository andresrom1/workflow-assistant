<?php

namespace App\Http\Controllers;

use App\Models\EmergencyTrackingToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint público para que los contactos vean la ubicación compartida.
 *
 * Spec v2 §4.3: URL del tracking = mango.broker/track/{token}. Sin auth,
 * se accede desde el link de WhatsApp o Share nativo. El token es la
 * autorización de LECTURA (la de escritura es el `update_secret`, que nunca
 * sale del device).
 *
 * Content-negotiation:
 *   - `Accept: application/json` (la app, el polling del mapa) → JSON.
 *   - browser (un contacto abriendo el link) → HTML con mapa Leaflet/OSM.
 *
 * Estados:
 *   - active=true → last_lat, last_lon, last_updated_at
 *   - active=false → "ya no está disponible"
 */
class TrackingController extends Controller
{
    public function show(Request $request, string $token): JsonResponse|View
    {
        $row = EmergencyTrackingToken::where('token', $token)->first();
        $active = $row !== null && $row->isActive();

        if ($request->expectsJson()) {
            if (! $active) {
                return response()->json([
                    'active' => false,
                    'message' => 'Esta ubicación ya no está disponible.',
                ], Response::HTTP_NOT_FOUND);
            }

            /** @var EmergencyTrackingToken $row */
            return response()->json([
                'active' => true,
                'last_lat' => $row->last_lat,
                'last_lon' => $row->last_lon,
                'last_updated_at' => $row->last_updated_at?->toIso8601String(),
                'expires_at' => $row->expires_at->toIso8601String(),
            ]);
        }

        // Browser: siempre 200 con la página; el JS se encarga del estado y
        // del polling. Si está inactivo, la página muestra "ya no disponible".
        return view('track', [
            'token' => $token,
            'active' => $active,
            'lastLat' => $active ? (float) $row->last_lat : null,
            'lastLon' => $active ? (float) $row->last_lon : null,
            'lastUpdatedAt' => $active ? $row->last_updated_at?->toIso8601String() : null,
        ]);
    }
}
