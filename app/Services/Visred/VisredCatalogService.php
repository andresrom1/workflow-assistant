<?php

namespace App\Services\Visred;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catálogos de params de Visred que el dominio necesita ofrecer como opciones
 * (p. ej. condiciones fiscales del titular). Normaliza la respuesta del proveedor
 * a la shape neutra `{ref, label}` y la cachea (cambian muy poco).
 *
 * El dominio nunca importa esta clase: la consume la capa de canal (el controller
 * de checkout) para poblar un select. Ver docs/v2/10 §3.
 */
class VisredCatalogService
{
    public function __construct(private readonly VisredClient $client) {}

    /**
     * Condiciones fiscales (`tax_condition_id`) para el holder, como `{ref, label}`.
     * Cacheado; tolerante a fallo (devuelve [] para no romper el checkout). La ruta
     * exacta vive en config y está pendiente de verificación live (D6).
     *
     * @return list<array{ref: string, label: string}>
     */
    public function taxConditions(): array
    {
        $ttl = (int) config('visred.tax_conditions_ttl', 86400);

        return Cache::remember('visred:tax_conditions', $ttl, function (): array {
            try {
                $rows = $this->client->get((string) config('visred.tax_conditions_path'));

                return $this->normalize($rows);
            } catch (Throwable $e) {
                Log::warning('[VisredCatalog] No se pudo traer tax-conditions', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Normaliza filas heterogéneas del proveedor a `{ref, label}`. Tolera distintos
     * nombres de campo (id/ref/code, name/label/description) porque el shape exacto
     * aún no está verificado live.
     *
     * @return list<array{ref: string, label: string}>
     */
    private function normalize(mixed $rows): array
    {
        if (! is_iterable($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = $row['id'] ?? $row['ref'] ?? $row['code'] ?? null;
            $label = $row['name'] ?? $row['label'] ?? $row['description'] ?? $ref;

            if (is_scalar($ref) && (string) $ref !== '') {
                $out[] = ['ref' => (string) $ref, 'label' => (string) (is_scalar($label) ? $label : $ref)];
            }
        }

        return $out;
    }
}
