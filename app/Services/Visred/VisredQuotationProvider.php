<?php

namespace App\Services\Visred;

use App\Contracts\QuotationProvider;
use App\Models\RiskProviderRef;
use App\Models\RiskSnapshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Adapter de cotización Visred (implementa el puerto {@see QuotationProvider}).
 *
 * Lee el `version_id` ya resuelto de `risk_provider_refs` (lo dejó el gate de
 * quotability en identify-vehicle), traduce el `RiskSnapshot` → request de Visred,
 * dispara `cotizar/` (async → TaskList) y hace polling acotado de las tasks. Aplana
 * `result.quotation_results[]` a la shape neutra de MANGO que consume
 * QuoteRepository::saveResults; el nombre de compañía se resuelve desde `company_id`
 * vía /discovery/companies/.
 *
 * El dominio nunca importa esta clase (se elige por config en Fase 4). El mapeo
 * cover→normalized_grade vive SOLO acá. Ver docs/v2/08 §§2/3/4.
 */
class VisredQuotationProvider implements QuotationProvider
{
    private const COTIZAR_PATH = '/v1/patrimoniales/vehicles/cotizar/';

    private const COMPANIES_PATH = '/v1/discovery/companies/';

    public function __construct(private readonly VisredClient $client) {}

    /**
     * @return array{
     *     task_id: string,
     *     status: string,
     *     raw: array<string, mixed>,
     *     parsed_alternatives: list<array<string, mixed>>
     * }
     */
    public function generateAlternatives(RiskSnapshot $snapshot): array
    {
        $versionId = $this->resolvedVersionId($snapshot);

        $taskList = $this->client->post(self::COTIZAR_PATH, $this->buildRequest($snapshot, $versionId));
        $taskIds = $this->taskIds($taskList);

        $taskResults = $this->poll($taskIds);
        // Solo resolvemos nombres de compañía si hubo resultados (evita la llamada
        // a /discovery/companies/ cuando se agotó el budget o todo falló).
        $companies = $taskResults === [] ? [] : $this->companyNames();

        $alternatives = [];
        foreach ($taskResults as $result) {
            foreach ($this->flatten($result, $companies) as $alternative) {
                $alternatives[] = $alternative;
            }
        }

        return [
            'task_id' => $taskIds[0] ?? uniqid('visred_'),
            'status' => $alternatives === [] ? 'FAILURE' : 'SUCCESS',
            'raw' => ['source' => 'Visred', 'cotizar' => $taskList, 'tasks' => $taskResults],
            'parsed_alternatives' => $alternatives,
        ];
    }

    /**
     * Token opaco del catálogo ya resuelto en quote-time (store por snapshot).
     */
    private function resolvedVersionId(RiskSnapshot $snapshot): string
    {
        $ref = RiskProviderRef::query()
            ->where('risk_snapshot_id', $snapshot->id)
            ->where('provider', VisredQuotabilityResolver::PROVIDER)
            ->value('external_vehicle_ref');

        if (! is_string($ref) || $ref === '') {
            throw new RuntimeException("No hay version_id resuelto de Visred para el snapshot {$snapshot->id}.");
        }

        return $ref;
    }

    /**
     * RiskSnapshot (dominio) → QuotationVehicleRequest (Visred). Ver docs/v2/08 §2.2.
     *
     * @return array<string, mixed>
     */
    private function buildRequest(RiskSnapshot $snapshot, string $versionId): array
    {
        $isGnc = strtolower((string) $snapshot->combustible) === 'gnc';

        return [
            'product_id' => 'auto',
            'address' => ['zip_code' => (int) $snapshot->codigo_postal],
            'person_holder' => ['document_number' => (string) $snapshot->dni],
            'vehicle' => [
                'version_id' => $versionId,
                'year' => $snapshot->year,
                'zero_kilometers' => false,
                'fuel_type_id' => $isGnc ? 'gnc' : 'sin-gnc',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $taskList
     * @return list<string>
     */
    private function taskIds(array $taskList): array
    {
        $tasks = $taskList['tasks_list'] ?? [];
        if (! is_array($tasks)) {
            return [];
        }

        $ids = [];
        foreach ($tasks as $task) {
            $id = is_array($task) ? ($task['task_id'] ?? null) : null;
            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Polling acotado por budget. Tolerante a parcial: devuelve las tasks que
     * resolvieron (SUCCESS) e ignora las que fallan (FAILURE) o no llegan a tiempo.
     *
     * @param  list<string>  $taskIds
     * @return list<array<string, mixed>>
     */
    private function poll(array $taskIds): array
    {
        $budget = (int) config('visred.poll_budget', 120);
        $interval = max(1, (int) config('visred.poll_interval', 4));

        $pending = array_fill_keys($taskIds, true);
        $results = [];
        $elapsed = 0;

        while ($pending !== []) {
            foreach (array_keys($pending) as $taskId) {
                $task = $this->client->get("/v1/tasks/{$taskId}/");
                $status = is_string($task['status'] ?? null) ? $task['status'] : 'PENDING';

                if ($status === 'SUCCESS') {
                    $result = $task['result'] ?? null;
                    if (is_array($result)) {
                        $results[] = $result;
                    }
                    unset($pending[$taskId]);
                } elseif ($status === 'FAILURE') {
                    Log::warning('[VisredQuote] Task con FAILURE, se omite', ['task_id' => $taskId]);
                    unset($pending[$taskId]);
                }
            }

            if ($pending === [] || $elapsed + $interval > $budget) {
                break; // todo terminal, o se agotó el budget → devolver parcial.
            }

            Sleep::for($interval)->seconds();
            $elapsed += $interval;
        }

        return $results;
    }

    /**
     * Mapa company_id → nombre legible desde /discovery/companies/.
     *
     * Se consulta en vivo en cada cotización (sin cache): el listado cambia muy
     * poco y una llamada extra es despreciable frente a cotizar/ + polling, sin
     * el refresco "al pedo" de un TTL fijo. Si el volumen lo pidiera, una
     * estrategia de refresco (cache/cron) entraría ACÁ. La traducción id→nombre
     * vive solo en el adapter (regla de desacople: el dominio no ve company_id).
     *
     * @return array<string, string>
     */
    private function companyNames(): array
    {
        $companies = $this->client->get(self::COMPANIES_PATH);

        $map = [];
        foreach ($companies as $company) {
            if (! is_array($company)) {
                continue;
            }
            $id = $company['company_id'] ?? null;
            $name = $company['company_name'] ?? null;
            if (is_scalar($id) && (string) $id !== '' && is_scalar($name) && (string) $name !== '') {
                $map[(string) $id] = (string) $name;
            }
        }

        return $map;
    }

    /**
     * Aplana el resultado de una task (una company) a alternativas neutras MANGO.
     * Visred devuelve `result.company_id` (slug) + `result.quotation_results[]`
     * (una entrada por cobertura). Filtra placeholders/inactivas (ver mapCover).
     *
     * @param  array<string, mixed>  $taskResult
     * @param  array<string, string>  $companies  company_id → nombre legible
     * @return list<array<string, mixed>>
     */
    private function flatten(array $taskResult, array $companies): array
    {
        $companyId = is_scalar($taskResult['company_id'] ?? null) ? (string) $taskResult['company_id'] : '';
        $companyName = $companies[$companyId] ?? ($companyId !== '' ? $companyId : 'Aseguradora');

        $results = $taskResult['quotation_results'] ?? null;
        if (! is_array($results)) {
            return [];
        }

        $alternatives = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $alternative = $this->mapCover($result, $companyName);
            if ($alternative !== null) {
                $alternatives[] = $alternative;
            }
        }

        return $alternatives;
    }

    /**
     * APIBaseQuotationResultDTO → alternativa de dominio, o `null` si no es
     * presentable. Visred devuelve filas placeholder (`cover.id`/`name` vacíos) e
     * ítems discontinuados (`active=false`): se descartan. Ver mapping docs/v2/08 §4.
     *
     * @param  array<string, mixed>  $coverResult
     * @return array<string, mixed>|null
     */
    private function mapCover(array $coverResult, string $companyName): ?array
    {
        $cover = is_array($coverResult['cover'] ?? null) ? $coverResult['cover'] : [];
        $coverName = is_scalar($cover['name'] ?? null) ? (string) $cover['name'] : '';
        $isActive = ($cover['active'] ?? false) === true;

        // Solo coberturas presentables (con nombre) y vigentes (active).
        if ($coverName === '' || ! $isActive) {
            return null;
        }

        $coverId = is_scalar($cover['id'] ?? null) ? (string) $cover['id'] : '';
        $insuredAmount = (int) ($coverResult['insured_amount'] ?? 0);

        return [
            // external_* los stripea saveResults hacia quote_provider_refs (ADR-001).
            'external_quote_id' => (string) ($coverResult['quotation_result_id'] ?? ''),
            'external_code' => $coverId,
            'aseguradora' => $companyName,
            'titulo' => $coverName,
            'descripcion' => is_scalar($cover['description'] ?? null) ? (string) $cover['description'] : $coverName,
            'normalized_grade' => $this->normalizedGrade($coverId, $coverName),
            'precio' => round((float) ($coverResult['fee'] ?? 0), 2),
            'moneda' => 'ARS',
            'marketing_title' => "{$companyName} - {$coverName}",
            'sum_insured_text' => $insuredAmount > 0 ? '$ '.number_format($insuredAmount, 0, ',', '.') : '',
            'features_tags' => $this->featureNames($coverResult['features'] ?? []),
            'full_details' => $this->featureDetails($coverResult['features'] ?? []),
        ];
    }

    /**
     * Mapeo cover → grade normalizado de MANGO (SOLO en el adapter).
     */
    private function normalizedGrade(string $coverId, string $coverName): string
    {
        $haystack = strtolower($coverId.' '.$coverName);

        return match (true) {
            str_contains($haystack, 'todo riesgo'), str_contains($haystack, 'todo-riesgo'), str_contains($haystack, 'all-risk') => 'all_risk',
            str_contains($haystack, 'terceros completo'), str_contains($haystack, 'terceros-completo') => 'third_party_complete',
            str_contains($haystack, 'rc'), str_contains($haystack, 'responsabilidad civil'), str_contains($haystack, 'tercero') => 'liability',
            default => 'basic',
        };
    }

    /**
     * @return list<string>
     */
    private function featureNames(mixed $features): array
    {
        if (! is_array($features)) {
            return [];
        }

        $names = [];
        foreach ($features as $feature) {
            $name = is_array($feature) ? ($feature['name'] ?? null) : null;
            if (is_scalar($name) && (string) $name !== '') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function featureDetails(mixed $features): array
    {
        if (! is_array($features)) {
            return [];
        }

        $details = [];
        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $name = is_scalar($feature['name'] ?? null) ? (string) $feature['name'] : null;
            if ($name !== null && $name !== '') {
                $details[$name] = is_scalar($feature['description'] ?? null) ? (string) $feature['description'] : 'Incluido.';
            }
        }

        return $details;
    }
}
