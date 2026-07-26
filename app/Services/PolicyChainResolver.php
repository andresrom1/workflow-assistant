<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Customer;
use App\Models\InsurableAsset;
use App\Models\Risk;
use App\Repositories\CustomerRepository;
use App\Support\DocumentoIdentidad;

/**
 * Find-or-create de la cadena de dominio Customer→InsurableAsset→Risk, agnóstico del
 * canal/fuente. Único punto de dedup de Risk de todo el sistema (ver docs/v3/05).
 *
 * Extraído de {@see IngestaConfirmacionService} para compartirlo con el import de reportes
 * de cartera ({@see PolicyReportConfirmacionService}), la emisión ({@see PolicyReferenceService})
 * y el alta manual ({@see PolizaService}) sin duplicar la regla de dedup. Depende sólo del
 * dominio: el alta de cliente pasa SIEMPRE por {@see CustomerMergeService} (nunca crea
 * `customers` directo); la identidad vive en el {@see InsurableAsset}, llaveado por
 * `natural_key` derivada por tipo ({@see AssetType::naturalKey}). El Risk es 1:1 con su
 * asset (transicional, ver docblock de {@see Risk}).
 */
class PolicyChainResolver
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerMergeService $merge,
        private readonly CustomerIdentificationService $identification,
    ) {}

    /**
     * Cliente existente por clave de identidad canónica ({@see DocumentoIdentidad}: DNI para
     * físicas, CUIT para jurídicas), o alta nueva vía dedup. `$documentType`/`$personType` fijan
     * el tipo del documento; si no vienen, la clave se infiere por el prefijo del número.
     *
     * @param  array{first_name?: ?string, last_name?: ?string, razon_social?: ?string}  $names
     */
    public function resolveCustomer(string $dni, array $names, ?string $documentType = null, ?string $personType = null): Customer
    {
        $clave = DocumentoIdentidad::clave($dni, $documentType, $personType);

        // La búsqueda de identidad va SIEMPRE por el servicio de identificación, como toda
        // puerta por la que entra un cliente: acá se pasan los tipos declarados del documento,
        // que el servicio usa para derivar la identidad (DNI si es física, CUIT si es jurídica).
        $existing = $this->identification->findCustomer('dni', $dni, $documentType, $personType);

        if ($existing instanceof Customer) {
            return $existing;
        }

        $firstName = $names['first_name'] ?? null;
        $lastName = $names['last_name'] ?? null;
        $razonSocial = $names['razon_social'] ?? null;
        $name = $razonSocial ?: trim((string) $firstName.' '.(string) $lastName);

        // `documento_key` lo calcula el hook `saving` del modelo desde dni+tipo (única fuente).
        $customer = $this->customers->create(array_filter([
            'dni' => $dni,
            'document_type_id' => $documentType,
            'person_type_id' => $personType,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name !== '' ? $name : null,
        ], fn ($v): bool => $v !== null && $v !== ''));

        // Alta SIEMPRE vía dedup: colapsa cualquier fila que ya posea esta identidad (no-op si
        // recién la creamos, load-bearing cuando convergen claves fuertes).
        return $this->merge->reconcile($customer, ['documento_key' => $clave]);
    }

    /**
     * Risk del cliente para el bien dado, o alta nueva. La identidad vive en el Asset: si
     * el tipo tiene clave natural (vehicle → patente normalizada) se deduplica por
     * (customer, type, natural_key); sin clave, cada contrato crea su propio asset (p. ej.
     * AP/vida: dos pólizas del mismo cliente NUNCA comparten Risk — decisión de dominio).
     *
     * @param  array<string, mixed>  $assetMeta  atributos del bien crudos (patente, marca, modelo, ...)
     */
    public function resolveRisk(Customer $customer, AssetType $type, array $assetMeta): Risk
    {
        $asset = $this->resolveAsset($customer, $type, $assetMeta);

        $risk = $asset->risks()->first();
        if ($risk instanceof Risk) {
            return $risk;
        }

        return $asset->risks()->create([
            'customer_id' => $customer->id,
            'type' => $type,
            'label' => $asset->label,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $assetMeta
     */
    private function resolveAsset(Customer $customer, AssetType $type, array $assetMeta): InsurableAsset
    {
        $key = $type->naturalKey($assetMeta);

        if ($key !== null) {
            $asset = $customer->assets()->where('type', $type)->where('natural_key', $key)->first();
            if ($asset instanceof InsurableAsset) {
                $this->backfillMissing($asset, $assetMeta);

                return $asset;
            }
        }

        return $customer->assets()->create([
            'type' => $type,
            'label' => $this->labelFor($type, $assetMeta),
            'metadata' => array_filter($assetMeta, fn ($v): bool => $v !== null && $v !== ''),
        ]);
    }

    /**
     * Rellena en el asset los atributos que trae la fuente entrante y que hoy faltan
     * (convergencia multi-fuente: p. ej. el reporte de cartera crea el asset con solo
     * la patente y la ingesta/emisión aportan marca/modelo después). Monótono: nunca
     * pisa un valor existente con uno vacío. La corrección de conflictos entre dos
     * valores no vacíos NO se hace acá — queda para el modelo de consolidación con
     * provenance/pesos por fuente, igual que en Customer (ver docs/v2/11).
     *
     * @param  array<string, mixed>  $assetMeta
     */
    private function backfillMissing(InsurableAsset $asset, array $assetMeta): void
    {
        $metadata = $asset->metadata ?? [];
        $changed = false;

        foreach ($assetMeta as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                continue;
            }
            $metadata[$field] = $value;
            $changed = true;
        }

        if ($changed) {
            $asset->update(['metadata' => $metadata]);
        }
    }

    /**
     * @param  array<string, mixed>  $assetMeta
     */
    private function labelFor(AssetType $type, array $assetMeta): string
    {
        $marca = trim((string) ($assetMeta['marca'] ?? ''));
        $modelo = trim((string) ($assetMeta['modelo'] ?? ''));
        $patente = trim((string) ($assetMeta['patente'] ?? ''));
        $label = trim("{$marca} {$modelo}").($patente !== '' ? " ({$patente})" : '');

        return $label !== '' ? $label : $type->label();
    }
}
