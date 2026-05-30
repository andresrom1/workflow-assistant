<?php

namespace App\Console\Commands;

use App\Services\Smn\FcmTopicPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Smoke test FCM + utilidad de testing manual del feature 4.4 (alertas meteo).
 *
 * Publica un payload data-only sintético al topic `acp-ar` con un polígono
 * hardcodeado alrededor de una ciudad conocida. Sirve dos propósitos:
 *
 *   1. Validar que las credenciales del service account funcionan para FCM
 *      (antes del primer poll real al SMN).
 *   2. Verificar el end-to-end en el device:
 *      - `smn:test-push carlos-paz` → si el GPS del device está adentro del
 *         polígono de Carlos Paz, dispara notificación local en la app.
 *      - `smn:test-push villa-maria` → polígono lejos del device,
 *         la app debe recibirlo y descartar silenciosamente.
 *
 * No persiste nada en `acp_procesados`: este comando es ad-hoc.
 *
 * Spec v2 §4.4. Fase 4 backend.
 */
class SmnTestPush extends Command
{
    protected $signature = 'smn:test-push
                            {ciudad : Ciudad del polígono (carlos-paz | villa-maria)}
                            {--severity=Severe : Minor|Moderate|Severe|Extreme}';

    protected $description = 'Publica un ACP sintético al topic acp-ar para testing del feature 4.4.';

    /**
     * Polígonos rectangulares ~5km de lado alrededor de cada ciudad. Cubren la
     * mancha urbana sin solaparse con localidades vecinas — facilita comprobar
     * que el device dentro/fuera reacciona como se espera.
     *
     * Coordenadas en formato [lat, lon], polígono cerrado (primer punto = último).
     *
     * @var array<string, array{nombre: string, area_desc: string, polygon: list<array{0: float, 1: float}>}>
     */
    private const POLIGONOS = [
        'carlos-paz' => [
            'nombre' => 'Villa Carlos Paz',
            // Formato real del SMN: "PROVINCIA: DEPARTAMENTO(S).".
            'area_desc' => 'CÓRDOBA: PUNILLA.',
            // Caja amplia sobre el Gran Carlos Paz (~15 km) para el E2E — sigue
            // muy lejos de Villa María, así que el test "afuera" no se ve afectado.
            'polygon' => [
                [-31.36, -64.58],
                [-31.36, -64.42],
                [-31.50, -64.42],
                [-31.50, -64.58],
                [-31.36, -64.58],
            ],
        ],
        'villa-maria' => [
            'nombre' => 'Villa María',
            'area_desc' => 'CÓRDOBA: GENERAL SAN MARTÍN.',
            'polygon' => [
                [-32.39, -63.26],
                [-32.39, -63.21],
                [-32.43, -63.21],
                [-32.43, -63.26],
                [-32.39, -63.26],
            ],
        ],
    ];

    private const SEVERIDADES_VALIDAS = ['Minor', 'Moderate', 'Severe', 'Extreme'];

    public function handle(FcmTopicPublisher $publisher): int
    {
        $ciudad = (string) $this->argument('ciudad');
        $severity = (string) $this->option('severity');

        if (! array_key_exists($ciudad, self::POLIGONOS)) {
            $this->error('Ciudad inválida. Opciones: '.implode(', ', array_keys(self::POLIGONOS)));

            return self::INVALID;
        }

        if (! in_array($severity, self::SEVERIDADES_VALIDAS, true)) {
            $this->error('Severity inválida. Opciones: '.implode(', ', self::SEVERIDADES_VALIDAS));

            return self::INVALID;
        }

        $config = self::POLIGONOS[$ciudad];
        $now = Carbon::now();

        $acp = [
            'id' => 'urn:oid:test.'.Str::uuid()->toString(),
            'msg_type' => 'Alert',
            // Mock fiel al shape real del SMN: el `event` incluye el nivel
            // ("AVISO NARANJA") y la `instruction` viene como "---" (vacío),
            // tal cual los CAP reales del SMN. No inventar localidades ni textos.
            'event' => 'TORMENTAS FUERTES CON LLUVIAS INTENSAS, RÁFAGAS Y OCASIONAL CAÍDA DE GRANIZO - AVISO NARANJA',
            'severity' => $severity,
            'expires_at' => $now->copy()->addHour(),
            'area_desc' => $config['area_desc'],
            'polygon' => $config['polygon'],
            'instruction' => '---',
        ];

        $this->info('Publicando ACP de prueba al topic acp-ar:');
        $this->line('  ciudad   : '.$config['nombre']);
        $this->line('  severity : '.$severity);
        $this->line('  id       : '.$acp['id']);
        $this->line('  expires  : '.$acp['expires_at']->toIso8601String());

        try {
            $publisher->publish($acp);
        } catch (Throwable $e) {
            $this->error('Error al publicar al topic: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('OK. El push debería llegar a todos los devices suscriptos a acp-ar.');

        return self::SUCCESS;
    }
}
