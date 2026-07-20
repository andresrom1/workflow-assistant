<?php

namespace App\Services\Visred;

use App\Contracts\DiscountPolicy;
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

    private const DISCOUNT_PATH = '/v1/patrimoniales/vehicles/params/discount/';

    /**
     * Combustible de dominio (`RiskSnapshot.combustible`) → `fuel_type_id` de Visred.
     *
     * **Es un binario `sin-gnc` | `gnc`, NO el combustible específico.** En cotización
     * `fuel_type_id` responde "¿tiene equipo de GNC?" (el correlato de `has_gnc` en
     * emisión), no "qué combustible quema". Aunque `/params/fuel-type/` lista 7 ids
     * (`nafta`/`diesel`/`electrico`/`hibrido`/`gnc`/`con-gnc`/`sin-gnc`) y el schema
     * publicado deja `fuel_type_id` como string SIN enum, **cada compañía valida los
     * valores por su cuenta río abajo**: Galicia y Río Uruguay (RUS) rechazan todo lo
     * que no sea `sin-gnc`/`gnc` con `Input should be 'sin-gnc' or 'gnc'` → la task
     * termina en FAILURE (no un 400 en `cotizar/`). El par binario es la INTERSECCIÓN
     * que aceptan las 13 compañías, así que un solo request las sirve a todas.
     *
     * Verificado live contra `/v1/schema/` (2026-07-20): un único campo de combustible
     * en `QuotationVehicleDataRequest`, sin enum; la emisión pregunta GNC como booleano
     * (`has_gnc` en `PreSaleVehicleDataRequest`). Ver docs/v2/08 §2.2.
     *
     * Sin default: lo desconocido se OMITE (el campo es opcional) para no asumir GNC.
     */
    private const FUEL_MAP = [
        'gnc' => 'gnc',
        'con-gnc' => 'gnc',
        'sin-gnc' => 'sin-gnc',
        'nafta' => 'sin-gnc',
        'diesel' => 'sin-gnc',
        'gasoil' => 'sin-gnc',
        'gas-oil' => 'sin-gnc',
        'gasoleo' => 'sin-gnc',
        'electrico' => 'sin-gnc',
        'electrica' => 'sin-gnc',
        'electric' => 'sin-gnc',
        'hibrido' => 'sin-gnc',
        'hybrid' => 'sin-gnc',
    ];

    public function __construct(
        private readonly VisredClient $client,
        private readonly DiscountPolicy $discountPolicy,
    ) {}

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
        $taskCompanies = $this->taskCompanies($taskList);
        $taskIds = array_keys($taskCompanies);

        $outcome = ['succeeded' => [], 'failed' => [], 'pending' => []];
        $taskResults = $this->poll($taskIds, $taskCompanies, $outcome);
        $this->logQuoteSummary($taskCompanies, $outcome);

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
        $vehicle = [
            'version_id' => $versionId,
            'year' => $snapshot->year,
            'zero_kilometers' => false,
        ];

        // fuel_type_id es opcional: solo se envía si el combustible de dominio
        // mapea a un id conocido del catálogo. Sin default (no asumir combustible).
        $fuel = $this->fuelTypeId($snapshot->combustible);
        if ($fuel !== null) {
            $vehicle['fuel_type_id'] = $fuel;

            // Visred exige `insured_amount_fuel` cuando el equipo es GNC. El monto no
            // se captura aún en cotización → default configurable (refinado en emisión).
            if ($fuel === 'gnc') {
                $vehicle['insured_amount_fuel'] = (int) config('visred.default_gnc_amount', 1_500_000);
            }
        }

        $request = [
            'product_id' => 'auto',
            'address' => ['zip_code' => (int) $snapshot->codigo_postal],
            'vehicle' => $vehicle,
        ];

        // person_holder: varias compañías (San Cristóbal, Galicia) RECHAZAN la
        // cotización sin DNI (FAILURE "person_holder es requerido"), así que se manda
        // cuando el snapshot ya tiene el DNI real del cliente. Si todavía no lo tiene,
        // el bloque se OMITE (mandar document_number vacío gatilla un 400) — perdemos
        // esas dos compañías en esa cotización puntual, pero la emisión no se rompe.
        // NO usar un placeholder: Visred exige que el document_number de la emisión
        // coincida con el de la cotización, y "coincida con un valor inventado" no es
        // alcanzable — verificado en prod (ver docs/v2/08 §2.2 y ROADMAP Bitácora
        // 2026-07-19). NO se re-cotiza al capturar el DNI real en checkout.
        $dni = trim((string) $snapshot->dni);
        if ($dni !== '') {
            $request['person_holder'] = ['document_number' => $dni];
        }

        return $request;
    }

    /**
     * Mapea el combustible de dominio al `fuel_type_id` del catálogo, o `null` si
     * no se reconoce (se omite el campo en vez de asumir uno).
     */
    private function fuelTypeId(?string $combustible): ?string
    {
        $key = strtr(mb_strtolower(trim((string) $combustible)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        return self::FUEL_MAP[$key] ?? null;
    }

    /**
     * task_id → company_id (slug) desde el TaskList. El map permite atribuir un
     * FAILURE a su compañía en el log: el body de la task fallida solo trae el
     * task_id (UUID), no el slug. Tasks sin id se omiten; sin company_id → ''.
     *
     * @param  array<string, mixed>  $taskList
     * @return array<string, string>
     */
    private function taskCompanies(array $taskList): array
    {
        $tasks = $taskList['tasks_list'] ?? [];
        if (! is_array($tasks)) {
            return [];
        }

        $map = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = $task['task_id'] ?? null;
            if (is_scalar($id) && (string) $id !== '') {
                $map[(string) $id] = is_scalar($task['company_id'] ?? null) ? (string) $task['company_id'] : '';
            }
        }

        return $map;
    }

    /**
     * Polling acotado por budget. Tolerante a parcial: devuelve las tasks que
     * resolvieron (SUCCESS) e ignora las que fallan (FAILURE) o no llegan a tiempo.
     *
     * `$outcome` (out-param) recibe el desglose de task_ids por estado terminal
     * (`succeeded`/`failed`/`pending`) para el resumen por-compañía que loguea
     * {@see self::logQuoteSummary()}. Contar tasks resueltas vs fallidas —no tasks
     * creadas— es lo que detecta una compañía caída de un vistazo.
     *
     * @param  list<string>  $taskIds
     * @param  array<string, string>  $taskCompanies  task_id → company_id, para atribuir FAILURE en el log.
     * @param  array{succeeded: list<string>, failed: list<string>, pending: list<string>}  $outcome
     * @return list<array<string, mixed>>
     */
    private function poll(array $taskIds, array $taskCompanies = [], array &$outcome = ['succeeded' => [], 'failed' => [], 'pending' => []]): array
    {
        $budget = (int) config('visred.poll_budget', 120);
        $interval = max(1, (int) config('visred.poll_interval', 4));

        $pending = array_fill_keys($taskIds, true);
        $results = [];
        $outcome = ['succeeded' => [], 'failed' => [], 'pending' => []];
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
                    $outcome['succeeded'][] = $taskId;
                    unset($pending[$taskId]);
                } elseif ($status === 'FAILURE') {
                    // Capturamos el cuerpo del FAILURE para diagnosticar el motivo por
                    // compañía: algunas exigen person_holder/DNI para cotizar y lo omitimos
                    // cuando aún no se capturó (→ field_errors). El body de una task fallida
                    // es chico (sin quotation_results). Ver Causa B.
                    Log::warning('[VisredQuote] Task con FAILURE, se omite', [
                        'task_id' => $taskId,
                        'company_id' => ($taskCompanies[$taskId] ?? '') !== '' ? $taskCompanies[$taskId] : null,
                        'reason' => $this->failureReason($task),
                        'body' => $task,
                    ]);
                    $outcome['failed'][] = $taskId;
                    unset($pending[$taskId]);
                }
            }

            if ($pending === [] || $elapsed + $interval > $budget) {
                break; // todo terminal, o se agotó el budget → devolver parcial.
            }

            Sleep::for($interval)->seconds();
            $elapsed += $interval;
        }

        $outcome['pending'] = array_keys($pending); // no terminaron dentro del budget.

        return $results;
    }

    /**
     * Resumen de una cotización, en una línea: cuántas tasks resolvieron vs fallaron
     * vs no llegaron a tiempo, y con qué compañías. Los FAILURE individuales ya se
     * loguean en {@see self::poll()}; este resumen los junta para que una cotización
     * degradada (p. ej. 2 de 13 compañías caídas) se vea de un vistazo sin reconstruir
     * el conteo a mano — el agujero que dejó pasar la regresión de `fuel_type_id`.
     *
     * @param  array<string, string>  $taskCompanies  task_id → company_id
     * @param  array{succeeded: list<string>, failed: list<string>, pending: list<string>}  $outcome
     */
    private function logQuoteSummary(array $taskCompanies, array $outcome): void
    {
        $companiesOf = fn (array $taskIds): array => array_values(array_unique(array_filter(
            array_map(fn (string $id): string => $taskCompanies[$id] ?? '', $taskIds),
            fn (string $company): bool => $company !== '',
        )));

        $total = count($outcome['succeeded']) + count($outcome['failed']) + count($outcome['pending']);

        Log::info('[VisredQuote] Resumen de cotización', [
            'tasks_total' => $total,
            'succeeded' => count($outcome['succeeded']),
            'failed' => count($outcome['failed']),
            'pending' => count($outcome['pending']),
            'failed_companies' => $companiesOf($outcome['failed']),
            'pending_companies' => $companiesOf($outcome['pending']),
        ]);
    }

    /**
     * Extrae un motivo legible del cuerpo de una task con FAILURE. La shape exacta
     * no está documentada (la task termina en SUCCESS|FAILURE; el detalle del fallo
     * no se especifica) → sondeamos los lugares habituales: `message`, `error`
     * (string u objeto `{message}`), `detail`, y los mismos dentro de `result`.
     * Devuelve `null` si no encuentra nada presentable (el `body` completo igual se
     * loguea aparte).
     *
     * @param  array<string, mixed>  $task
     */
    private function failureReason(array $task): ?string
    {
        $result = is_array($task['result'] ?? null) ? $task['result'] : [];

        foreach ([$task, $result] as $source) {
            foreach (['message', 'detail'] as $key) {
                if (is_scalar($source[$key] ?? null) && (string) $source[$key] !== '') {
                    return (string) $source[$key];
                }
            }

            $error = $source['error'] ?? null;
            if (is_scalar($error) && (string) $error !== '') {
                return (string) $error;
            }
            if (is_array($error) && is_scalar($error['message'] ?? null) && (string) $error['message'] !== '') {
                return (string) $error['message'];
            }
        }

        return null;
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

        // Descuento elegido para ESTA compañía (máximo por defecto). Se aplica al
        // fee acá (cotizar no acepta discount_id) y su ref se persiste para mandarlo
        // en la emisión → el precio cotizado coincide con el cobrado.
        $discount = $companyId !== '' ? $this->companyDiscount($companyId) : null;

        $alternatives = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $alternative = $this->mapCover($result, $companyName, $companyId, $discount);
            if ($alternative !== null) {
                $alternatives[] = $alternative;
            }
        }

        return $alternatives;
    }

    /**
     * Descuento elegido para una compañía: trae su catálogo de bonificaciones
     * (`{value, discount, description}`, por-compañía) y delega la selección a la
     * {@see DiscountPolicy} (agnóstica). La obtención es Visred-específica → vive
     * acá; la decisión de cuál, en la política. `null` si la compañía no bonifica.
     *
     * @return array{ref: string, percent: float}|null
     */
    private function companyDiscount(string $companyId): ?array
    {
        $payload = $this->client->get(self::DISCOUNT_PATH, ['company_id' => $companyId, 'product_id' => 'auto']);
        // Tolera respuesta top-level [...] o envuelta en results/data.
        $rows = $payload['results'] ?? $payload['data'] ?? $payload;

        $discounts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = $row['value'] ?? null;
            $percent = $row['discount'] ?? null;
            if (is_scalar($ref) && (string) $ref !== '' && is_numeric($percent)) {
                $discounts[] = ['ref' => (string) $ref, 'percent' => (float) $percent];
            }
        }

        // Tope del productor para ESTA compañía (no lo expone Visred → config).
        $caps = (array) config('visred.max_discount_percent', []);
        $cap = (float) ($caps[$companyId] ?? $caps['default'] ?? 0);

        return $this->discountPolicy->choose($discounts, $cap);
    }

    /**
     * APIBaseQuotationResultDTO → alternativa de dominio, o `null` si no es
     * presentable. Visred devuelve filas placeholder (`cover.id`/`name` vacíos):
     * se descartan. `cover.active=false` NO se filtra — Visred lo marca en planes
     * vendibles (B/C/D, M PLUS, Auto Max 6…) que igual cotiza con fee real; filtrar
     * por ese flag escondía coberturas válidas. Ver mapping docs/v2/08 §4.
     *
     * @param  array<string, mixed>  $coverResult
     * @param  string  $companyId  Slug opaco de la compañía (lo persiste saveResults).
     * @param  array{ref: string, percent: float}|null  $discount  Bonificación elegida (se aplica al fee).
     * @return array<string, mixed>|null
     */
    private function mapCover(array $coverResult, string $companyName, string $companyId, ?array $discount): ?array
    {
        $cover = is_array($coverResult['cover'] ?? null) ? $coverResult['cover'] : [];
        $coverName = is_scalar($cover['name'] ?? null) ? (string) $cover['name'] : '';

        // Solo se descartan placeholders (sin nombre). NO se filtra por `cover.active`:
        // Visred marca active=false en coberturas vendibles que igual cotiza con fee
        // real (Mercantil B/M PLUS, Sancor Auto Max 6, RUS…); sus placeholders vienen
        // con name/id vacíos → se atrapan acá por nombre.
        if ($coverName === '') {
            return null;
        }

        $coverId = is_scalar($cover['id'] ?? null) ? (string) $cover['id'] : '';
        $insuredAmount = (int) ($coverResult['insured_amount'] ?? 0);

        // Aplica la bonificación al fee (aproximación: % lineal sobre el premio
        // cotizado; el premio bonificado exacto lo fija la compañía al emitir).
        $baseFee = round((float) ($coverResult['fee'] ?? 0), 2);
        $percent = $discount['percent'] ?? 0.0;
        $precio = $percent > 0 ? round($baseFee * (1 - $percent / 100), 2) : $baseFee;

        return [
            // external_*, company_id, discount_id y requires_inspection_* los stripea
            // saveResults hacia quote_alternative_provider_refs (ADR-001): tokens del
            // proveedor que la emisión necesita, fuera de la tabla de dominio.
            'external_quote_id' => (string) ($coverResult['quotation_result_id'] ?? ''),
            'external_code' => $coverId,
            'company_id' => $companyId,
            'discount_id' => $discount['ref'] ?? null,
            'requires_inspection_before_emission' => ($coverResult['require_inspection_before_emission'] ?? false) === true,
            'aseguradora' => $companyName,
            'titulo' => $coverName,
            'descripcion' => is_scalar($cover['description'] ?? null) ? (string) $cover['description'] : $coverName,
            'normalized_grade' => $this->normalizedGrade($coverId, $coverName),
            'precio' => $precio,
            'sum_asegurada' => $insuredAmount > 0 ? $insuredAmount : null,
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
