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

        // Estado 2: crear token activo, devolver tracking_url + update_secret.
        $token = $this->createTrackingToken($account, (float) $data['lat'], (float) $data['lon']);

        // TODO real: dispatchar WhatsApp con tracking_url a los contactos.
        // Deuda agendada (ver ROADMAP §Deuda): depende de la WhatsApp Business
        // API, todavía no integrada en el monorepo (misma deuda que /siniestro).

        return response()->json([
            'ok' => true,
            'estado' => 2,
            'token' => $token->token,
            'tracking_url' => $this->trackingUrl($token->token),
            // El secreto de escritura viaja SOLO en esta respuesta hacia el
            // device. No va en la URL ni se comparte con contactos: es la
            // única llave para postear posición (PATCH .../posicion).
            'update_secret' => $token->update_secret,
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }

    /**
     * PATCH .../emergencia/tracking/{token}/posicion — el device actualiza su
     * última posición cada ~2 min mientras el Estado 2 está activo.
     *
     * SIN auth:mobile: lo invoca el foreground service en un isolate que no
     * tiene el Sanctum token. La autorización es el `update_secret` (decisión
     * de seguridad C): solo el device que generó el tracking lo conoce.
     */
    public function updatePosition(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'update_secret' => ['required', 'string'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $row = EmergencyTrackingToken::where('token', $token)->first();

        // 404 neutro: no distinguimos "token inexistente" de "secreto inválido"
        // para no filtrar qué tokens existen. Comparación en tiempo constante.
        if (! $row || $row->update_secret === null
            || ! hash_equals($row->update_secret, $data['update_secret'])) {
            throw new ApiException('No encontramos ese tracking activo.', 'TRACKING_NOT_FOUND', 404);
        }

        // 410 Gone: el secreto es correcto pero el tracking ya murió (revocado
        // en Estado 4 o venció a las 4h). El device debe frenar el servicio.
        if (! $row->isActive()) {
            throw new ApiException('El tracking ya no está activo.', 'TRACKING_INACTIVE', 410);
        }

        $row->update([
            'last_lat' => $data['lat'],
            'last_lon' => $data['lon'],
            'last_updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'expires_at' => $row->expires_at->toIso8601String(),
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
            'update_secret' => Str::random(64),
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
