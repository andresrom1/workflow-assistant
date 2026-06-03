<?php

namespace App\Http\Controllers;

use App\Models\EmergencyTrackingToken;
use App\Models\EmergencyTrackPosition;
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
 *   - `Accept: application/json` (el polling del mapa) → JSON.
 *   - browser (un contacto abriendo el link) → HTML con mapa Leaflet/OSM.
 *
 * Replay con offset (mejora de cadencia §4.3): el device sube un batch de
 * posiciones cada ~40s; acá NO devolvemos la más nueva sino la que "toca"
 * reproducir ahora = la última con `effective_at <= now() - REPLAY_OFFSET`.
 * Resultado: el mapa se mueve suave cada ~10s con un atraso ~constante
 * (≈ REPLAY_OFFSET) en vez de saltar cada 2 min.
 */
class TrackingController extends Controller
{
    /**
     * Atraso de reproducción (segundos). Tiene que ser ≥ el intervalo de subida
     * del device (~40s) para que el cursor de replay nunca se quede sin datos.
     * 70s da ~30s de colchón sobre ese mínimo (≈ 1:10 de atraso, decidido).
     */
    private const REPLAY_OFFSET_SECONDS = 70;

    public function show(Request $request, string $token): JsonResponse|View
    {
        $row = EmergencyTrackingToken::where('token', $token)->first();
        $active = $row !== null && $row->isActive();

        $position = $active ? $this->replayPosition($row) : null;

        if ($request->expectsJson()) {
            if (! $active) {
                return response()->json([
                    'active' => false,
                    'message' => 'Esta ubicación ya no está disponible.',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'active' => true,
                'last_lat' => $position['lat'],
                'last_lon' => $position['lon'],
                'last_updated_at' => $position['at'],
                'expires_at' => $row->expires_at->toIso8601String(),
            ]);
        }

        // Browser: siempre 200 con la página; el JS se encarga del estado y
        // del polling. Si está inactivo, la página muestra "ya no disponible".
        return view('track', [
            'token' => $token,
            'active' => $active,
            'lastLat' => $position !== null ? (float) $position['lat'] : null,
            'lastLon' => $position !== null ? (float) $position['lon'] : null,
            'lastUpdatedAt' => $position['at'] ?? null,
        ]);
    }

    /**
     * Posición a mostrar ahora según el replay con offset.
     *
     * 1) La última del buffer con `effective_at <= now() - offset` (el caso normal).
     * 2) Si todavía no hay ninguna "due" (recién arrancó, < offset transcurrido),
     *    la más vieja del buffer, para que el mapa muestre el punto de partida ya.
     * 3) Si no hay buffer todavía, el `last_*` del token (primer fix del Estado 2).
     *
     * @return array{lat: string, lon: string, at: string|null}|null
     */
    private function replayPosition(EmergencyTrackingToken $row): ?array
    {
        $cutoff = now()->subSeconds(self::REPLAY_OFFSET_SECONDS);

        $due = EmergencyTrackPosition::where('emergency_tracking_token_id', $row->id)
            ->where('effective_at', '<=', $cutoff)
            ->orderByDesc('effective_at')
            ->first();

        $position = $due ?? EmergencyTrackPosition::where('emergency_tracking_token_id', $row->id)
            ->orderBy('effective_at')
            ->first();

        if ($position !== null) {
            return [
                'lat' => (string) $position->lat,
                'lon' => (string) $position->lon,
                'at' => $position->effective_at->toIso8601String(),
            ];
        }

        // Sin buffer todavía: caemos al primer fix guardado en el token.
        if ($row->last_lat === null || $row->last_lon === null) {
            return null;
        }

        return [
            'lat' => (string) $row->last_lat,
            'lon' => (string) $row->last_lon,
            'at' => $row->last_updated_at?->toIso8601String(),
        ];
    }
}
