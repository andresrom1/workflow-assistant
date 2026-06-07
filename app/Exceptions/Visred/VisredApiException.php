<?php

namespace App\Exceptions\Visred;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Excepción tipada para errores de la API de Visred.
 *
 * Normaliza el envelope de error de Visred
 * ({ success:false, error:{ message, code, field_errors } }) a una excepción
 * con `status` HTTP, `code` machine-readable y `field_errors` por campo.
 *
 * Capa anticorrupción: la lanza el VisredClient (capa HTTP). El Adapter de
 * cotización/emisión (Fase 3+) la mapea a las excepciones de dominio de MANGO
 * — el dominio nunca ve esta clase ni el envelope crudo de Visred (ADR-001).
 *
 * Ver docs/v2/08-visred-quote-adapter.md §2.5.
 */
class VisredApiException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Código estable de Visred (e.g. 'validation_error',
     *                             'not_authenticated', 'external_service_unavailable').
     * @param  array<string, list<string>>  $fieldErrors  Errores por campo (solo en validation_error).
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Construye la excepción desde una respuesta de error de Visred,
     * tolerando respuestas que no traen el envelope canónico.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $error = $response->json('error');

        if (! is_array($error)) {
            return new self(
                "Visred respondió {$status} sin el envelope de error esperado.",
                $status,
                self::defaultCodeForStatus($status),
            );
        }

        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Visred respondió un error {$status}.";

        $code = is_string($error['code'] ?? null) && $error['code'] !== ''
            ? $error['code']
            : self::defaultCodeForStatus($status);

        return new self(
            $message,
            $status,
            $code,
            self::normalizeFieldErrors($error['field_errors'] ?? null),
        );
    }

    /**
     * Falla de red (Visred inalcanzable / timeout) → se presenta como un 503
     * con el mismo code que usa Visred para indisponibilidad de servicio externo,
     * para que el Adapter lo trate como "cotización no disponible".
     */
    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self(
            'No se pudo conectar con Visred: '.$exception->getMessage(),
            503,
            'external_service_unavailable',
            [],
            $exception,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * Mapea el HTTP status al código estable de Visred cuando la respuesta no
     * trae `error.code` (o no trae envelope). Ver docs/v2/08 §2.5.
     */
    private static function defaultCodeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'validation_error',
            401 => 'not_authenticated',
            403 => 'permission_denied',
            404 => 'not_found',
            409 => 'conflict',
            503 => 'external_service_unavailable',
            default => 'error',
        };
    }

    /**
     * Normaliza `field_errors` (dict[str, list[str]] de Visred) a una shape
     * estable, tolerando valores escalares o estructuras inesperadas.
     *
     * @return array<string, list<string>>
     */
    private static function normalizeFieldErrors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $field => $messages) {
            $list = is_array($messages) ? $messages : [$messages];

            $normalized[(string) $field] = array_values(array_map(
                static fn (mixed $message): string => is_scalar($message) ? (string) $message : '',
                $list,
            ));
        }

        return $normalized;
    }
}
