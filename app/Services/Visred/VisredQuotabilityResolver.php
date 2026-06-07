<?php

namespace App\Services\Visred;

use App\AI\Agents\DisambiguationAgent;
use App\Contracts\Quotability;
use App\Exceptions\Visred\VisredApiException;
use App\Models\Vehicle;
use App\Services\Quotability\QuotabilityResult;
use Illuminate\Support\Facades\Log;

/**
 * Implementación Visred del puerto {@see Quotability}.
 *
 * Encapsula TODO lo del proveedor: navega el árbol de params (5 GETs,
 * RESOLVER-DESIGN.md §10), normaliza shapes heterogéneos a {ref,label},
 * desambigua (Tier 1 léxico → Tier 2 LLM, §9) y, si resuelve, refina
 * `Vehicle.version` (dominio) y devuelve el token opaco como `externalRef`.
 *
 * El dominio/canal NUNCA importa esta clase: dependen del puerto. Un error de
 * Visred se traduce a la rama honesta `NotQuotable` (sin promesa rota, §8).
 */
class VisredQuotabilityResolver implements Quotability
{
    public const PROVIDER = 'visred';

    private const PRODUCT = 'auto';

    /** Alias de marca → nombre del catálogo (normalizado). */
    private const BRAND_ALIASES = [
        'VW' => 'VOLKSWAGEN',
        'CHEVY' => 'CHEVROLET',
        'MERCEDES' => 'MERCEDES BENZ',
        'MERCEDESBENZ' => 'MERCEDES BENZ',
    ];

    public function __construct(
        private readonly VisredClient $client,
        private readonly DisambiguationAgent $disambiguator,
    ) {}

    public function check(Vehicle $vehicle): QuotabilityResult
    {
        try {
            return $this->resolve($vehicle);
        } catch (VisredApiException $e) {
            // Error transitorio/persistente del proveedor → rama honesta (§8):
            // "te tengo el auto, no puedo cotizarlo ahora" — sin mentir identidad.
            Log::warning('[VisredQuotability] No se pudo resolver el catálogo', [
                'vehicle_id' => $vehicle->id,
                'status' => $e->status(),
                'code' => $e->errorCode(),
            ]);

            return QuotabilityResult::notQuotable();
        }
    }

    private function resolve(Vehicle $vehicle): QuotabilityResult
    {
        // 1. vehicle-types → confirmar que "auto" está disponible para este usuario.
        $types = $this->level($this->client->get('/v1/patrimoniales/vehicles/params/vehicle-types/'));
        if (! $this->hasVehicleType($types, self::PRODUCT)) {
            return QuotabilityResult::notQuotable();
        }

        // 2. brands → resolver marca (exacto normalizado + alias).
        $brands = $this->level($this->client->get('/v1/patrimoniales/vehicles/params/auto/brands/'));
        $brandId = $this->matchRef($brands, $this->brandNeedle($vehicle->marca), 'id', 'description');
        if ($brandId === null) {
            return QuotabilityResult::notQuotable();
        }

        // 3. years → el año es estructural (nodo del árbol), match exacto.
        $years = $this->level($this->client->get("/v1/patrimoniales/vehicles/params/auto/{$brandId}/years/"));
        $yearId = $this->matchRef($years, (string) $vehicle->year, 'id', 'description');
        if ($yearId === null) {
            return QuotabilityResult::notQuotable();
        }

        // 4. groups → modelo. Beam: junto las versiones de TODOS los groups que
        //    matchean exacto (numéricos "208"/"2008" pegan exacto; fuzzy proscripto).
        $groups = $this->level($this->client->get("/v1/patrimoniales/vehicles/params/auto/{$brandId}/{$yearId}/groups/"));
        $groupIds = $this->matchAllRefs($groups, $this->normalize($vehicle->modelo), 'group_id', 'group_name');
        if ($groupIds === []) {
            return QuotabilityResult::notQuotable();
        }

        // 5. versions → candidatos neutros {ref,label} (puede venir de varios groups).
        $candidates = [];
        foreach ($groupIds as $groupId) {
            $versions = $this->level($this->client->get(
                "/v1/patrimoniales/vehicles/params/auto/{$brandId}/{$yearId}/versions/",
                ['group_id' => $groupId],
            ));
            foreach ($versions as $version) {
                $ref = $this->stringField($version, 'version_id');
                $label = $this->stringField($version, 'version_name');
                if ($ref !== null && $label !== null) {
                    $candidates[] = ['ref' => $ref, 'label' => $label];
                }
            }
        }

        if ($candidates === []) {
            return QuotabilityResult::notQuotable();
        }

        return $this->disambiguate($vehicle, $candidates);
    }

    /**
     * Cascada de desambiguación (§9): Tier 1 léxico → Tier 2 LLM.
     *
     * @param  list<array{ref: string, label: string}>  $candidates
     */
    private function disambiguate(Vehicle $vehicle, array $candidates): QuotabilityResult
    {
        $clientTokens = $this->tokens($vehicle->version ?? '');

        // Tier 1a — igualdad exacta única (candado del re-cotizar, §7).
        $exact = array_values(array_filter(
            $candidates,
            fn (array $c): bool => $this->normalize($c['label']) === $this->normalize($vehicle->version ?? ''),
        ));
        if (count($exact) === 1) {
            return $this->quotable($vehicle, $exact[0]);
        }

        // Tier 1b — subconjunto léxico con hit único (margen).
        $subset = array_values(array_filter(
            $candidates,
            fn (array $c): bool => $clientTokens !== [] && $this->isSubset($clientTokens, $this->tokens($c['label'])),
        ));
        if (count($subset) === 1) {
            return $this->quotable($vehicle, $subset[0]);
        }

        // Tier 2 — LLM (parsea version_name; agnóstico de proveedor).
        return $this->disambiguateWithLlm($vehicle, $candidates);
    }

    /**
     * @param  list<array{ref: string, label: string}>  $candidates
     */
    private function disambiguateWithLlm(Vehicle $vehicle, array $candidates): QuotabilityResult
    {
        $labels = array_map(fn (array $c): string => $c['label'], $candidates);

        $prompt = 'Cliente dijo (versión): "'.($vehicle->version ?? '').'"'."\n"
            .'Auto: '.$vehicle->marca.' '.$vehicle->modelo.' '.$vehicle->year."\n"
            ."Candidatos:\n- ".implode("\n- ", $labels);

        $decision = $this->parseDecision($this->disambiguator->prompt($prompt)->text);

        if ($decision === null) {
            return QuotabilityResult::notQuotable();
        }

        if ($decision['decision'] === 'resolved') {
            $chosen = $this->candidateByLabel($candidates, (string) ($decision['version_name'] ?? ''));

            return $chosen === null
                ? QuotabilityResult::notQuotable()
                : $this->quotable($vehicle, $chosen);
        }

        // need_fact: ambigüedad reencuadrada como hecho de dominio faltante (§8).
        return QuotabilityResult::needsFact(
            (string) ($decision['missing_fact'] ?? 'la versión exacta'),
            $this->stringList($decision['options'] ?? []),
        );
    }

    /**
     * Resuelto: refina `Vehicle.version` (DOMINIO) y emite el token opaco.
     *
     * @param  array{ref: string, label: string}  $candidate
     */
    private function quotable(Vehicle $vehicle, array $candidate): QuotabilityResult
    {
        if ($vehicle->version !== $candidate['label']) {
            $vehicle->version = $candidate['label'];
            $vehicle->save();
        }

        return QuotabilityResult::quotable($candidate['label'], self::PROVIDER, $candidate['ref']);
    }

    // ── Helpers de catálogo (normalización de shapes heterogéneos, §10) ──────

    /**
     * Extrae la lista de un nivel: tolera respuesta top-level `[...]` o envuelta
     * en `results`/`data`.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function level(array $payload): array
    {
        $list = $payload;
        if (array_is_list($payload) === false) {
            $list = $payload['results'] ?? $payload['data'] ?? [];
        }

        return array_values(array_filter(
            is_array($list) ? $list : [],
            is_array(...),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $types
     */
    private function hasVehicleType(array $types, string $product): bool
    {
        foreach ($types as $type) {
            if (($type['vehicle_type_id'] ?? null) === $product) {
                return true;
            }
        }

        return false;
    }

    /**
     * Primer ref cuyo label normalizado == needle. Devuelve el ref como string.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function matchRef(array $rows, string $needle, string $refKey, string $labelKey): ?string
    {
        foreach ($rows as $row) {
            if ($this->normalize($this->stringField($row, $labelKey) ?? '') === $needle) {
                $ref = $row[$refKey] ?? null;

                return is_scalar($ref) ? (string) $ref : null;
            }
        }

        return null;
    }

    /**
     * Todos los refs cuyo label normalizado == needle (beam para modelo↔trim).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function matchAllRefs(array $rows, string $needle, string $refKey, string $labelKey): array
    {
        $refs = [];
        foreach ($rows as $row) {
            if ($this->normalize($this->stringField($row, $labelKey) ?? '') === $needle) {
                $ref = $row[$refKey] ?? null;
                if (is_scalar($ref)) {
                    $refs[] = (string) $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * @param  list<array{ref: string, label: string}>  $candidates
     * @return array{ref: string, label: string}|null
     */
    private function candidateByLabel(array $candidates, string $label): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['label'] === $label) {
                return $candidate;
            }
        }

        return null;
    }

    private function brandNeedle(string $marca): string
    {
        $normalized = $this->normalize($marca);

        return self::BRAND_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Normaliza: mayúsculas, sin acentos, alfanumérico + espacios, colapsado.
     */
    private function normalize(string $value): string
    {
        $upper = mb_strtoupper(trim($value));
        $ascii = strtr($upper, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $clean = preg_replace('/[^A-Z0-9 ]+/', ' ', $ascii) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    private function isSubset(array $needle, array $haystack): bool
    {
        return array_diff($needle, $haystack) === [];
    }

    /**
     * Parsea el JSON estricto del agente (tolerante a texto envolvente).
     *
     * @return array<string, mixed>|null
     */
    private function parseDecision(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded) || ! in_array($decoded['decision'] ?? null, ['resolved', 'need_fact'], true)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value),
            fn (string $v): bool => $v !== '',
        ));
    }
}
