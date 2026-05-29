<?php

namespace App\Console\Commands;

use App\Models\AcpProcesado;
use App\Services\Smn\CapFeedParser;
use App\Services\Smn\FcmTopicPublisher;
use App\Services\Smn\SmnAcpFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll del feed CAP del SMN → fan-out a FCM topic `acp-ar`.
 *
 * Orquesta el flujo completo del feature 4.4 (backend):
 *   1. Baja el RSS índice del SMN.
 *   2. Por cada XML CAP individual: lo baja, lo parsea, lo filtra.
 *   3. Filtra por severidad (solo Severe + Extreme — decisión de producto).
 *   4. Dedup contra `acp_procesados` (mismo cap:identifier no se reenvía).
 *   5. Publica el push silencioso al topic y persiste el id.
 *   6. Cleanup de filas vencidas hace >7 días.
 *
 * El backend NUNCA conoce ubicaciones de clientes — solo hace fan-out al topic.
 * El point-in-polygon corre on-device. Spec v2 §4.4.
 *
 * Cadencia estacional (self-check del tick, ver shouldRunThisTick):
 *   - Oct-Mar (temporada de tormentas): cada 12 min.
 *   - Abr-Sep: cada 30 min.
 * El scheduler lo dispara cada 5 min y el comando decide si es su turno —
 * así cambiar la cadencia es una línea acá, sin tocar el scheduler.
 */
class SmnPollAcp extends Command
{
    protected $signature = 'smn:poll-acp {--force : Ignora el self-check estacional del tick}';

    protected $description = 'Poll del feed CAP del SMN y fan-out a FCM topic acp-ar (feature 4.4).';

    /**
     * Severidades que disparan push. Decisión de producto: solo avisos serios.
     */
    private const SEVERIDADES_PUBLICABLES = ['Severe', 'Extreme'];

    /**
     * Días de retención de filas en `acp_procesados` después de que vencen.
     */
    private const RETENCION_DIAS = 7;

    public function handle(
        SmnAcpFetcher $fetcher,
        CapFeedParser $parser,
        FcmTopicPublisher $publisher,
    ): int {
        $now = Carbon::now();

        if (! $this->option('force') && ! $this->shouldRunThisTick($now)) {
            return self::SUCCESS;
        }

        $rss = $fetcher->fetchRssIndex();
        if ($rss === null) {
            // El fetcher ya logueó el motivo. Reintentamos el próximo tick.
            $this->cleanup();

            return self::SUCCESS;
        }

        $urls = $parser->parseRssIndex($rss);
        $this->info('SMN RSS índice: '.count($urls).' aviso(s) en el feed.');

        $publicados = 0;
        $omitidos = 0;

        foreach ($urls as $url) {
            try {
                if ($this->procesarAviso($url, $fetcher, $parser, $publisher, $now)) {
                    $publicados++;
                } else {
                    $omitidos++;
                }
            } catch (Throwable $e) {
                // Un aviso roto no aborta la corrida: se descarta y seguimos.
                $omitidos++;
                Log::error('SMN poll-acp: error procesando aviso', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("SMN poll-acp: {$publicados} publicado(s), {$omitidos} omitido(s).");

        $this->cleanup();

        return self::SUCCESS;
    }

    /**
     * Baja, parsea, filtra, dedupea y publica un aviso individual.
     *
     * @return bool true si se publicó al topic; false si se descartó/omitió.
     */
    private function procesarAviso(
        string $url,
        SmnAcpFetcher $fetcher,
        CapFeedParser $parser,
        FcmTopicPublisher $publisher,
        Carbon $now,
    ): bool {
        $xml = $fetcher->fetchCapXml($url);
        if ($xml === null) {
            return false;
        }

        $acp = $parser->parseCapAlert($xml, $now);
        if ($acp === null) {
            return false;
        }

        // Filtro de severidad (producto: solo Severe + Extreme).
        if (! in_array($acp['severity'], self::SEVERIDADES_PUBLICABLES, true)) {
            return false;
        }

        // Dedup server-side: si ya lo publicamos, no reenviar.
        if (AcpProcesado::query()->whereKey($acp['id'])->exists()) {
            return false;
        }

        $publisher->publish($acp);

        AcpProcesado::query()->create([
            'id' => $acp['id'],
            'expires_at' => $acp['expires_at'],
            'processed_at' => $now,
        ]);

        $this->line('  → publicado: '.$acp['id'].' ('.$acp['severity'].')');

        return true;
    }

    /**
     * Determina si este tick del scheduler (cada 5 min) corresponde correr.
     *
     * Temporada de tormentas (octubre–marzo): cada 12 min.
     * Resto del año (abril–septiembre): cada 30 min.
     */
    private function shouldRunThisTick(Carbon $now): bool
    {
        $esTemporadaTormentas = $now->month >= 10 || $now->month <= 3;
        $intervalo = $esTemporadaTormentas ? 12 : 30;

        return $now->minute % $intervalo === 0;
    }

    /**
     * Borra filas de avisos vencidos hace más de RETENCION_DIAS.
     * La tabla solo sirve para dedup mientras el aviso está vigente.
     */
    private function cleanup(): void
    {
        AcpProcesado::query()
            ->where('expires_at', '<', Carbon::now()->subDays(self::RETENCION_DIAS))
            ->delete();
    }
}
