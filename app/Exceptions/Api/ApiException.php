<?php

namespace App\Exceptions\Api;

use RuntimeException;

/**
 * Excepción base para errores de la API mobile.
 *
 * Spec v2 §5 "Formato de error estándar": las respuestas de error tienen
 * { message, code, errors? } con un HTTP status semántico. La app de Flutter
 * ramifica por `code` (SCREAMING_SNAKE_CASE) y muestra `message` directo al
 * usuario (texto en voz de marca).
 *
 * Subclasificar para errores recurrentes con copy fijo; usar directamente
 * para casos ad-hoc dentro de un endpoint.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  string  $message  Texto en voz de marca, apto para mostrar al usuario.
     * @param  string  $errorCode  Identificador estable (SCREAMING_SNAKE_CASE).
     * @param  int  $httpStatus  HTTP status para la respuesta.
     * @param  array<string, array<int, string>>|null  $errors  Errores por campo (estilo validation).
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 400,
        private readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, array<int, string>>|null */
    public function errors(): ?array
    {
        return $this->errors;
    }
}
