<?php

namespace App\Services;

use App\Exceptions\InvalidFirebaseTokenException;
use App\Services\Smn\FcmTopicPublisher;
use Kreait\Firebase\Contract\Messaging as FirebaseMessaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Publica un push data-only "documento nuevo disponible" al topic FCM por cuenta
 * (`account-{mobileAccountId}`), al que la app se suscribe al autenticarse.
 *
 * Espeja a {@see FcmTopicPublisher} pero apunta a un topic por
 * usuario en vez del topic global de alertas. data-only: la app decide cómo mostrarlo
 * (notificación local + refresco del Home). Todos los values van como string (límite
 * de FCM data messages).
 */
class DocumentAvailablePublisher
{
    private ?FirebaseMessaging $messaging = null;

    /**
     * @param  array<string, string>  $data  Payload extra (poliza_id, kind, …).
     */
    public function publishToAccount(int $mobileAccountId, array $data): void
    {
        $message = CloudMessage::new()
            ->withTopic("account-{$mobileAccountId}")
            ->withData(['type' => 'policy_document', ...$data]);

        $this->messaging()->send($message);
    }

    /**
     * Cliente Messaging perezoso: el error de credenciales sale al publicar, no al bootear.
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
