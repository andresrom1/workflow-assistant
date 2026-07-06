<?php

namespace App\Support;

/**
 * Clave de identidad canónica de un cliente a partir de su documento, agnóstica de proveedor
 * (reglas AFIP, no de Visred). Colapsa las distintas formas en que aparece la misma persona:
 *
 * - **Persona física** → su identidad es el **DNI**. El CUIL y el CUIT (si es responsable
 *   inscripto) tienen formato `PP-DNI-V` y **contienen el DNI** en el medio (prefijos 20/23/24/27),
 *   así que se reducen al DNI.
 * - **Persona jurídica** → solo tiene **CUIT** (prefijos 30/33/34); el medio NO es un DNI, la
 *   clave es el CUIT completo de 11 dígitos.
 *
 * Con esto, DNI `71784318` y CUIL `20-71784318-3` de la misma persona producen la misma clave,
 * y la dedup/lookup pueden comparar por igualdad.
 */
final class DocumentoIdentidad
{
    private const PREFIJOS_JURIDICA = ['30', '33', '34'];

    private const PREFIJOS_FISICA = ['20', '23', '24', '27'];

    /**
     * Solo los dígitos del número (descarta guiones/espacios). Null si queda vacío.
     */
    public static function normalizar(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $numero) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * Clave de persona: DNI para físicas (extraído del CUIL/CUIT si hace falta), CUIT completo
     * para jurídicas. Null si el número no alcanza a resolverse.
     */
    public static function clave(?string $numero, ?string $documentType = null, ?string $personType = null): ?string
    {
        $n = self::normalizar($numero);
        if ($n === null) {
            return null;
        }

        if (self::esJuridica($n, $documentType, $personType)) {
            // Jurídica: la clave es el CUIT completo (11 dígitos). Si vino un número corto,
            // igual devolvemos lo normalizado (no hay DNI que extraer).
            return $n;
        }

        // Física: reducir al DNI.
        if (strlen($n) === 11) {
            $n = substr($n, 2, 8); // PP-DNI-V → DNI de 8 posiciones
        }

        $dni = ltrim($n, '0');

        return $dni !== '' ? $dni : null;
    }

    /**
     * Tipo de persona inferido del prefijo de un CUIT/CUIL de 11 dígitos, para pre-seleccionar
     * el form. Null si el número no permite inferir (p. ej. un DNI de 7-8 dígitos).
     */
    public static function inferirTipoPersona(?string $numero): ?string
    {
        $n = self::normalizar($numero);
        if ($n === null || strlen($n) !== 11) {
            return null;
        }

        $prefijo = substr($n, 0, 2);

        return match (true) {
            in_array($prefijo, self::PREFIJOS_JURIDICA, true) => 'juridica',
            in_array($prefijo, self::PREFIJOS_FISICA, true) => 'fisica',
            default => null,
        };
    }

    /**
     * ¿La identidad se trata como jurídica? Un DNI o CUIL nunca lo es. Para un CUIT, prioriza el
     * tipo de persona declarado; si no vino, infiere por prefijo.
     */
    private static function esJuridica(string $numeroNorm, ?string $documentType, ?string $personType): bool
    {
        if ($documentType === 'dni' || $documentType === 'cuil') {
            return false;
        }

        if ($personType !== null && $personType !== '') {
            return $personType === 'juridica';
        }

        return self::inferirTipoPersona($numeroNorm) === 'juridica';
    }
}
