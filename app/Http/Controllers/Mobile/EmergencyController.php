<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmergencyTrackingToken;
use App\Models\MobileAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Necesito Ayuda" — spec v2 §4.3.
 *
 * Estado 1 (estoy bien): dispatcha WhatsApp con ubicación estática (en mock
 * no se dispatcha realmente; se devuelve { ok }).
 * Estado 2 (necesito que vengas): crea un EmergencyTrackingToken con TTL 4h
 * y devuelve la URL pública para compartir manualmente. Los contactos
 * agendados también reciben el link por WhatsApp (mock: no).
 *
 * Estado 3 (911) y Estado 4 (dejar de compartir) se manejan del lado del
 * cliente (`tel:911` directo, y DELETE de este endpoint para revocar).
 *
 * Dedup/rate-limit: throttle en la ruta — el spec pide ventana corta para
 * evitar doble-envío por retry de red.
 */
class EmergencyController extends Controller
{
    public function notify(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $data = $request->validate([
            'estado' => ['required', 'integer', 'in:1,2'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($data['estado'] === 1) {
            // TODO real: dispatchar WhatsApp con ubicación estática.
            return response()->json([
                'ok' => true,
                'estado' => 1,
            ]);
        }

        // Estado 2: crear/reutilizar token activo, devolver tracking_url.
        $token = $this->createTrackingToken($account, (float) $data['lat'], (float) $data['lon']);

        // TODO real: dispatchar WhatsApp con tracking_url a los contactos.

        return response()->json([
            'ok' => true,
            'estado' => 2,
            'token' => $token->token,
            'tracking_url' => $this->trackingUrl($token->token),
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }

    public function revokeTracking(Request $request, string $token): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $row = EmergencyTrackingToken::where('mobile_account_id', $account->id)
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if (! $row) {
            throw new ApiException('No encontramos ese tracking activo.', 'TRACKING_NOT_FOUND', 404);
        }

        $row->update(['revoked_at' => now()]);

        return response()->json([], 204);
    }

    private function createTrackingToken(MobileAccount $account, float $lat, float $lon): EmergencyTrackingToken
    {
        return EmergencyTrackingToken::create([
            'mobile_account_id' => $account->id,
            'token' => Str::random(48),
            'last_lat' => $lat,
            'last_lon' => $lon,
            'last_updated_at' => now(),
            'expires_at' => now()->addHours(EmergencyTrackingToken::DEFAULT_TTL_HOURS),
        ]);
    }

    private function trackingUrl(string $token): string
    {
        $base = rtrim((string) config('app.tracking_base_url', config('app.url')), '/');

        return $base.'/track/'.$token;
    }
}
