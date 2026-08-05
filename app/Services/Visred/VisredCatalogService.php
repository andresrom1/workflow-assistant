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
     * Marcas de tarjeta (`credit_card_brand_id`) que acepta la compañía, como `{ref, label}`.
     *
     * El catálogo es POR COMPAÑÍA y difiere de verdad (experta 7, triunfo 9, galicia 13):
     * el checkout ofrece las de la compañía que el cliente eligió. Si ese catálogo no está
     * disponible cae al global — siguen siendo PKs válidos de Visred, y un select vacío
     * bloquearía la venta por un hipo del endpoint.
     *
     * No se deduplican las descripciones repetidas (`amex` y `american-express` conviven
     * en varias compañías): son PKs distintos y no está verificado cuál acepta cada una.
     *
     * @return list<array{ref: string, label: string}>
     */
    public function creditCards(?string $companyId): array
    {
        $cards = $companyId !== null && $companyId !== ''
            ? $this->fetchCreditCards($companyId)
            : [];

        return $cards !== [] ? $cards : $this->fetchCreditCards(null);
    }

    /**
     * Una entrada de caché por compañía, más la del global. `null` = sin filtro.
     *
     * @return list<array{ref: string, label: string}>
     */
    private function fetchCreditCards(?string $companyId): array
    {
        $ttl = (int) config('visred.credit_cards_ttl', 86400);
        $key = 'visred:credit_cards:'.($companyId ?? '__global__');

        return Cache::remember($key, $ttl, function () use ($companyId): array {
            try {
                $rows = $this->client->get(
                    (string) config('visred.credit_cards_path'),
                    $companyId !== null ? ['company_id' => $companyId] : [],
                );

                return $this->normalize($rows);
            } catch (Throwable $e) {
                Log::warning('[VisredCatalog] No se pudo traer credit-cards', [
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                ]);

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
