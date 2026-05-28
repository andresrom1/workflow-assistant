<?php

namespace App\Services\Firebase;

/**
 * Identidad verificada que resulta de validar un Firebase ID Token.
 * Los datos del usuario móvil vienen 100% de Firebase (spec v2 §Autenticación).
 */
final readonly class VerifiedIdentity
{
    public function __construct(
        public string $uid,
        public ?string $email,
        public ?string $name,
        public ?string $avatarUrl,
        public bool $emailVerified,
    ) {}

    /**
     * Apple, cuando el usuario elige "Ocultar mi correo", entrega un email de
     * relay que nunca coincide con el del tomador. Esos usuarios se rechazan:
     * necesitamos el email real para identificarlos.
     */
    public function isAppleRelayEmail(): bool
    {
        return $this->email !== null
            && str_ends_with(strtolower($this->email), '@privaterelay.appleid.com');
    }
}
