<?php

namespace App\Services\Firebase;

use App\Exceptions\InvalidFirebaseTokenException;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Factory;
use Throwable;

/**
 * Implementación real con el Admin SDK de kreait (kreait/firebase-php).
 * Verifica firma, issuer, audience y expiración del ID Token contra Firebase.
 */
final class KreaitTokenVerifier implements FirebaseTokenVerifier
{
    private ?FirebaseAuth $auth = null;

    public function verify(string $idToken): VerifiedIdentity
    {
        try {
            $verified = $this->auth()->verifyIdToken($idToken);
        } catch (Throwable $e) {
            throw new InvalidFirebaseTokenException(
                'No se pudo verificar el token de Firebase: '.$e->getMessage(),
                previous: $e,
            );
        }

        $claims = $verified->claims();

        return new VerifiedIdentity(
            uid: (string) $claims->get('sub'),
            email: $claims->get('email'),
            name: $claims->get('name'),
            avatarUrl: $claims->get('picture'),
            emailVerified: (bool) $claims->get('email_verified', false),
        );
    }

    /**
     * Construye el cliente Auth de forma perezosa: si las credenciales no están
     * configuradas, el error sale recién al verificar (no al bootear la app).
     */
    private function auth(): FirebaseAuth
    {
        if ($this->auth instanceof FirebaseAuth) {
            return $this->auth;
        }

        $credentials = config('firebase.credentials');

        if (! is_string($credentials) || ! is_file($credentials)) {
            throw new InvalidFirebaseTokenException(
                'Credenciales de Firebase no encontradas. Configurá FIREBASE_CREDENTIALS '
                .'apuntando al JSON del service account.',
            );
        }

        return $this->auth = (new Factory)
            ->withServiceAccount($credentials)
            ->createAuth();
    }
}
