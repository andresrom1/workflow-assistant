<?php

namespace App\Services;

use App\Enums\RiskType;
use App\Models\Customer;
use App\Models\Risk;
use App\Repositories\CustomerRepository;
use App\Support\DocumentoIdentidad;

/**
 * Find-or-create de la cadena de dominio Customer→Risk, agnóstico del canal/fuente.
 *
 * Extraído de {@see IngestaConfirmacionService} para compartirlo con el import de reportes
 * de cartera ({@see PolicyReportConfirmacionService}) sin duplicar la regla de dedup. Depende
 * sólo del dominio: el alta de cliente pasa SIEMPRE por {@see CustomerMergeService} (nunca
 * crea `customers` directo); el Risk se llavea por `patente` dentro del cliente.
 */
class PolicyChainResolver
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerMergeService $merge,
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

        if ($clave !== null) {
            $existing = $this->customers->findByDocumentoKey($clave);
            if ($existing instanceof Customer) {
                return $existing;
            }
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
     * Risk existente del cliente por patente, o alta nueva. El Risk se llavea por patente:
     * sin patente no hay clave de dedup, por eso los llamadores la exigen.
     *
     * @param  array<string, mixed>  $riesgoMeta  atributos type-specific crudos (marca, modelo, year, ...)
     */
    public function resolveRisk(Customer $customer, string $patente, array $riesgoMeta): Risk
    {
        $patente = trim($patente);

        if ($patente !== '') {
            $risk = $customer->risks()->where('metadata->patente', $patente)->first();
            if ($risk instanceof Risk) {
                return $risk;
            }
        }

        $marca = trim((string) ($riesgoMeta['marca'] ?? ''));
        $modelo = trim((string) ($riesgoMeta['modelo'] ?? ''));
        $label = trim("{$marca} {$modelo}").($patente !== '' ? " ({$patente})" : '');

        return $customer->risks()->create([
            'type' => RiskType::Vehicle,
            'label' => $label !== '' ? $label : 'Vehículo',
            'metadata' => array_filter(
                ['patente' => $patente !== '' ? $patente : null] + $riesgoMeta,
                fn ($v): bool => $v !== null && $v !== '' && $v !== 'vehicle',
            ),
        ]);
    }
}
