<?php

namespace App\Services\Firebase;

use App\Exceptions\InvalidFirebaseTokenException;

/**
 * Verifica un Firebase ID Token y devuelve la identidad. Se inyecta como
 * interfaz para poder mockearla en tests sin pegarle a Firebase real.
 */
interface FirebaseTokenVerifier
{
    /**
     * @throws InvalidFirebaseTokenException si el token es inválido o expiró.
     */
    public function verify(string $idToken): VerifiedIdentity;
}
