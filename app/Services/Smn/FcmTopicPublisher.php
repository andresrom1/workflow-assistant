<?php

namespace App\Services\Smn;

use App\Exceptions\InvalidFirebaseTokenException;
use Illuminate\Support\Carbon;
use Kreait\Firebase\Contract\Messaging as FirebaseMessaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Publica avisos ACP al topic FCM `acp-ar` como push silencioso (data-only).
 *
 * Spec v2 §4.4:
 *   - Push silencioso: el SO no muestra nada. Despierta el código de la app para
 *     que lea el GPS on-device, ejecute point-in-polygon, y dispare una
 *     notificación local solo si está dentro del polígono.
 *   - El backend NO conoce ubicaciones de clientes. Solo hace fan-out al topic.
 *
 * Todos los valores del payload deben ser strings (limitación de FCM data messages).
 * El polígono se serializa a JSON y la app lo deserializa antes del point-in-polygon.
 */
class FcmTopicPublisher
{
    private const TOPIC = 'acp-ar';

    private ?FirebaseMessaging $messaging = null;

    /**
     * Publica un payload tipado de ACP (el shape que devuelve CapFeedParser) al
     * topic `acp-ar`. Lanza si las credenciales no están o FCM rechaza el mensaje.
     *
     * @param  array{
     *     id: string,
     *     msg_type: string,
     *     event: string,
     *     severity: string,
     *     expires_at: Carbon,
     *     area_desc: string,
     *     polygon: list<array{0: float, 1: float}>,
     *     instruction: string
     * }  $acp
     */
    public function publish(array $acp): void
    {
        // FCM data messages requieren string en todos los values.
        $data = [
            'type' => 'acp',
            'id' => $acp['id'],
            'msg_type' => $acp['msg_type'],
            'event' => $acp['event'],
            'severity' => $acp['severity'],
            'expires_at' => $acp['expires_at']->toIso8601String(),
            'area_desc' => $acp['area_desc'],
            'polygon' => json_encode($acp['polygon'], JSON_THROW_ON_ERROR),
            'instruction' => $acp['instruction'],
        ];

        $message = CloudMessage::new()
            ->withTopic(self::TOPIC)
            ->withData($data);

        $this->messaging()->send($message);
    }

    /**
     * Construye el cliente Messaging perezoso. Si las credenciales no están,
     * el error sale recién al publicar (no al bootear).
     */
    private function messaging(): FirebaseMessaging
    {
        if ($this->messaging instanceof FirebaseMessaging) {
            return $this->messaging;
        }

        $credentials = config('firebase.credentials');

        if (! is_string($credentials) || ! is_file($credentials)) {
            throw new InvalidFirebaseTokenException(
                'Credenciales de Firebase no encontradas. Configurá FIREBASE_CREDENTIALS '
                .'apuntando al JSON del service account.',
            );
        }

        return $this->messaging = (new Factory)
            ->withServiceAccount($credentials)
            ->createMessaging();
    }
}
