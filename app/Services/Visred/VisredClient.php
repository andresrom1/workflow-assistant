<?php

namespace App\Services\Visred;

use App\Exceptions\Visred\VisredApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Cliente HTTP + JWT de Visred (identidad de servicio del productor MANGO).
 *
 * Aísla el transporte y el ciclo de token del Adapter de dominio (Fase 3+):
 *   - Login/refresh contra `/v1/accounts/token/[refresh/]`, tokens cacheados
 *     server-side (Cache) — no en estado de instancia, así sirve transient.
 *   - `Authorization: Bearer <access>` + `Accept: application/json` en cada request.
 *   - Refresh-on-401: refresca y reintenta UNA vez; si el refresh falla, re-login
 *     con credenciales de servicio.
 *   - Normaliza el envelope de error de Visred a {@see VisredApiException}.
 *   - `X-Mock-Scenario` SOLO en sandbox (en prod → 403; ver docs/v2/08 §2.6).
 *
 * Espeja el patrón de App\Services\WhatsApp\WhatsAppOutboundService
 * (Http::withToken()->timeout()->retry()). El dominio nunca importa esta clase.
 *
 * Ver docs/v2/08-visred-quote-adapter.md §2.4 / §3.4.
 */
class VisredClient
{
    private const ACCESS_TOKEN_CACHE_KEY = 'visred:access_token';

    private const REFRESH_TOKEN_CACHE_KEY = 'visred:refresh_token';

    /** El access dura ~1h; cacheamos por debajo de eso. El 401-refresh es la red final. */
    private const ACCESS_TOKEN_TTL_SECONDS = 3300;

    private const REFRESH_TOKEN_TTL_SECONDS = 72000;

    private const RETRY_TIMES = 3;

    private const RETRY_SLEEP_MS = 200;

    private readonly string $baseUrl;

    private readonly string $username;

    private readonly string $password;

    private readonly int $timeout;

    private readonly bool $sandbox;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('visred.base_url'), '/');
        $this->username = (string) config('visred.username');
        $this->password = (string) config('visred.password');
        $this->timeout = (int) config('visred.timeout', 30);
        $this->sandbox = (bool) config('visred.sandbox', false);
    }

    /**
     * GET autenticado. `$query` se serializa como query string.
     *
     * @param  array<string, mixed>  $query
     * @param  string|null  $mockScenario  Forzado de escenario sandbox ('success'|'error_400'|'error_500');
     *                                     se envía SOLO si config('visred.sandbox') es true.
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], ?string $mockScenario = null): array
    {
        return $this->send('get', $path, $query, $mockScenario);
    }

    /**
     * POST autenticado. `$body` se envía como JSON.
     *
     * @param  array<string, mixed>  $body
     * @param  string|null  $mockScenario  Ver {@see self::get()}.
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?string $mockScenario = null): array
    {
        return $this->send('post', $path, $body, $mockScenario);
    }

    /**
     * Núcleo del transporte autenticado: arma el request con Bearer, dispara,
     * refresca-y-reintenta ante un 401, y normaliza errores/fallas de red.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data, ?string $mockScenario): array
    {
        $url = $this->url($path);

        try {
            $response = $this->dispatch(
                $this->authedRequest($this->accessToken(), $mockScenario),
                $method,
                $url,
                $data,
            );

            // Refresh-on-401: el access venció/se invalidó. Refrescá (o re-login) y
            // reintentá UNA sola vez — reintentar el mismo token no se arregla solo.
            if ($response->status() === 401) {
                $response = $this->dispatch(
                    $this->authedRequest($this->reauthenticate(), $mockScenario),
                    $method,
                    $url,
                    $data,
                );
            }
        } catch (ConnectionException $exception) {
            throw VisredApiException::connectionFailed($exception);
        }

        if ($response->failed()) {
            // Único punto de throw del cliente: loguear acá le da traza a TODAS las
            // rutas de Visred de una. Sin esto, un 4xx desde documentos, inspecciones,
            // catálogos o el polling de /tasks/ se pierde entero — solo emisión y
            // cotización loguean río abajo.
            //
            // SOLO el bloque `error` de la RESPUESTA. Nunca el request: el body de
            // `emitir/` lleva `credit_card_number` y `credit_card_holder`.
            Log::warning('[VisredClient] respuesta de error', [
                'path' => $path,
                'status' => $response->status(),
                'error' => Str::limit((string) json_encode($response->json('error')), 2000),
            ]);

            throw VisredApiException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(PendingRequest $request, string $method, string $url, array $data): Response
    {
        return match ($method) {
            'get' => $request->get($url, $data),
            'post' => $request->post($url, $data),
            default => throw new InvalidArgumentException("Método HTTP no soportado: {$method}"),
        };
    }

    /**
     * Request base autenticado: Accept JSON + Bearer + timeout + retry transitorio.
     * El `X-Mock-Scenario` se adjunta SOLO en sandbox (en prod gatillaría un 403).
     */
    private function authedRequest(string $accessToken, ?string $mockScenario): PendingRequest
    {
        $request = $this->baseRequest()->withToken($accessToken);

        if ($mockScenario !== null && $this->sandbox) {
            $request = $request->withHeaders(['X-Mock-Scenario' => $mockScenario]);
        }

        return $request;
    }

    /**
     * Request base sin auth (login/refresh): Accept JSON + timeout + retry transitorio.
     *
     * `throw: false` → devuelve la respuesta fallida en vez de tirar RequestException,
     * para inspeccionar el status (necesario para el branch de 401). El `when` evita
     * reintentar 4xx (no se arreglan reintentando); solo reintenta red/5xx/429.
     */
    private function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false, when: $this->shouldRetry(...));
    }

    /**
     * Política de reintento: solo errores transitorios (fallas de conexión, 5xx, 429).
     * Un 401 NO se reintenta acá — lo maneja el refresh-on-401 en {@see self::send()}.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Access token vigente: del cache, o login fresco si no hay.
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->login();
    }

    /**
     * Re-autenticación tras un 401: refresca con el refresh cacheado; si no hay
     * refresh o el refresh falla, cae a un re-login con credenciales de servicio.
     */
    private function reauthenticate(): string
    {
        $refresh = Cache::get(self::REFRESH_TOKEN_CACHE_KEY);

        if (is_string($refresh) && $refresh !== '') {
            try {
                return $this->refresh($refresh);
            } catch (VisredApiException) {
                // Refresh vencido/invalidado → re-login limpio abajo.
            }
        }

        return $this->login();
    }

    /**
     * `POST /v1/accounts/token/` con credenciales de servicio → cachea access+refresh.
     */
    private function login(): string
    {
        $response = $this->baseRequest()->post($this->url('/v1/accounts/token/'), [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if ($response->failed()) {
            throw VisredApiException::fromResponse($response);
        }

        $access = $response->json('access');
        $refresh = $response->json('refresh');

        if (! is_string($access) || $access === '') {
            throw new VisredApiException('El login de Visred no devolvió un access token.', $response->status(), 'invalid_response');
        }

        $this->storeTokens($access, is_string($refresh) ? $refresh : null);

        return $access;
    }

    /**
     * `POST /v1/accounts/token/refresh/` → nuevo access (y nuevo refresh si rota).
     */
    private function refresh(string $refreshToken): string
    {
        $response = $this->baseRequest()->post($this->url('/v1/accounts/token/refresh/'), [
            'refresh' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw VisredApiException::fromResponse($response);
        }

        $access = $response->json('access');
        $rotatedRefresh = $response->json('refresh');

        if (! is_string($access) || $access === '') {
            throw new VisredApiException('El refresh de Visred no devolvió un access token.', $response->status(), 'invalid_response');
        }

        // SimpleJWT con ROTATE_REFRESH_TOKENS devuelve un refresh nuevo; si no, se
        // conserva el actual.
        $this->storeTokens($access, is_string($rotatedRefresh) ? $rotatedRefresh : $refreshToken);

        return $access;
    }

    private function storeTokens(string $access, ?string $refresh): void
    {
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $access, self::ACCESS_TOKEN_TTL_SECONDS);

        if ($refresh !== null && $refresh !== '') {
            Cache::put(self::REFRESH_TOKEN_CACHE_KEY, $refresh, self::REFRESH_TOKEN_TTL_SECONDS);
        }
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
