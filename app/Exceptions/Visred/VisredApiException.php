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
 * `field_errors` queda SIEMPRE plano: los serializers anidados de Visred se
 * aplanan a claves con punto (`payment.credit_card_brand_id`). Ver
 * {@see self::normalizeFieldErrors()}.
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
     *                                                    La clave puede ser un path con puntos si el
     *                                                    error vino de un serializer anidado.
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
     * Errores por campo. La clave es el nombre del campo, o un path con puntos
     * (`payment.credit_card_brand_id`) cuando el error vino de un serializer
     * anidado de Visred.
     *
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
     * Normaliza `field_errors` de Visred a `array<string, list<string>>`,
     * APLANANDO las estructuras anidadas con notación de puntos:
     * `{"payment": {"credit_card_brand_id": ["Invalid pk"]}}`
     *   → `{"payment.credit_card_brand_id": ["Invalid pk"]}`.
     *
     * Visred corre Django REST Framework y sus serializers anidados (`payment`,
     * `person_holder`) y los `many=True` devuelven dicts y listas de dicts, NO el
     * `dict[str, list[str]]` plano que esta clase asumía. La versión anterior
     * mapeaba todo valor no-escalar a `''` y producía `{"payment": [""]}`:
     * destruía el mensaje Y el nombre del subcampo. Ver bitácora 2026-08-03.
     *
     * La shape pública se mantiene plana a propósito — el mail al equipo
     * (`emails/emision-fallida.blade.php`) hace `implode()` sobre el segundo
     * nivel — y las claves con punto son el idioma del validator de Laravel.
     *
     * @return array<string, list<string>>
     */
    private static function normalizeFieldErrors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        self::flattenFieldErrors($raw, '', $normalized);

        return $normalized;
    }

    /**
     * Recorre el árbol de errores acumulando en `$out` los mensajes por path.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, list<string>>  $out
     */
    private static function flattenFieldErrors(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $segment = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::flattenFieldErrors($value, $segment, $out);

                continue;
            }

            // null, objetos y string vacío no aportan texto: la clave directamente no
            // se emite. Antes se emitía '', que era indistinguible de un error real.
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }

            // El índice de una LISTA DE MENSAJES no es un campo: el bucket es el path
            // del padre (`product_id`, no `product_id.0`). En un dict —o en la raíz—
            // la clave sí es un segmento. Se acumula con `[]=`: dos ramas que caen en
            // el mismo path se suman en vez de pisarse.
            $out[is_int($key) && $prefix !== '' ? $prefix : $segment][] = (string) $value;
        }
    }
}
