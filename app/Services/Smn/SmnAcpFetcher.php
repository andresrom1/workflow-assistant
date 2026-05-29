<?php

namespace App\Services\Smn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Capa HTTP del polling al SMN. Aislada del parser para poder testear:
 *   - el parser sin tocar la red
 *   - el fetcher con `Http::fake()`
 *
 * Timeouts agresivos (3s connect / 8s total) + 1 retry porque corremos cada 5min
 * por cron; si el SMN está lento no quiero que el comando se trabe contra el
 * siguiente tick. withoutOverlapping(5) del scheduler es la red de seguridad final.
 *
 * Try/catch por aviso individual: un XML CAP roto o un timeout puntual descarta
 * ESE aviso, no aborta toda la corrida.
 *
 * Spec v2 §4.4. Fase 4 backend.
 */
class SmnAcpFetcher
{
    private const RSS_FEED_URL = 'https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/rss_acpCAP.xml';

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TOTAL_TIMEOUT_SECONDS = 8;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 500;

    /**
     * GET al RSS índice del SMN. Devuelve el XML crudo o null si el feed está
     * inalcanzable o respondió !=200.
     *
     * El parser se encarga de devolver "sin items" cuando el XML está vacío;
     * acá solo nos preocupamos de bajar el archivo.
     */
    public function fetchRssIndex(): ?string
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
                ->get(self::RSS_FEED_URL);

            if (! $response->successful()) {
                Log::warning('SMN RSS feed respondió status no exitoso', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (ConnectionException $e) {
            Log::warning('SMN RSS feed inalcanzable', ['error' => $e->getMessage()]);

            return null;
        } catch (Throwable $e) {
            // Cualquier otro error de red — no debería abortar el comando,
            // se intentará de nuevo en el próximo tick.
            Log::error('SMN RSS feed: error inesperado', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * GET a un XML CAP individual. Devuelve el XML crudo o null si falló.
     *
     * Llamada cara: por cada item del RSS índice se hace 1 GET. En operación normal
     * hay 0-3 ACPs activos, así que el costo es marginal. En caso patológico (decenas
     * de avisos) el `withoutOverlapping` del scheduler corta los excesos.
     */
    public function fetchCapXml(string $url): ?string
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('SMN CAP XML respondió status no exitoso', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (Throwable $e) {
            Log::warning('SMN CAP XML inalcanzable', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
