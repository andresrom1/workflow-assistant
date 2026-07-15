<?php

namespace App\Enums;

/**
 * Tipo de bien asegurable (InsurableAsset). Modelo ACORD simplificado: el Asset
 * es el bien estable y re-identificable (identidad); el Risk es la exposición
 * que lo referencia y de la que cuelgan las pólizas.
 *
 * Los valores en DB son compatibles con el viejo RiskType ('vehicle').
 */
enum AssetType: string
{
    case Vehicle = 'vehicle';
    case Property = 'property';
    case Person = 'person';
    case Business = 'business';
    case Equipment = 'equipment';

    /**
     * Clave natural de identidad del asset, derivada de sus atributos.
     * null = el tipo (todavía) no tiene identidad re-identificable en el mundo
     * → no se deduplica: cada contrato crea su propio asset.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function naturalKey(array $metadata): ?string
    {
        return match ($this) {
            self::Vehicle => self::normalize(isset($metadata['patente']) ? (string) $metadata['patente'] : null),
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Vehículo',
            self::Property => 'Inmueble',
            self::Person => 'Persona',
            self::Business => 'Comercio',
            self::Equipment => 'Equipo',
        };
    }

    /** Mayúsculas + solo alfanumérico: 'ab 235-or' → 'AB235OR'. Vacío → null. */
    private static function normalize(?string $value): ?string
    {
        $clean = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $value)));

        return $clean !== '' ? $clean : null;
    }
}
