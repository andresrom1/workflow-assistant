<?php

namespace App\Services\Visred;

use App\Contracts\EmissionProvider;
use App\Models\InspectionPhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Adapter de emisión Visred (implementa el puerto {@see EmissionProvider}).
 *
 * Traduce el request neutro de MANGO → `PreSaleVehicleRequest`, dispara
 * `emitir/` (async → un `TaskItem`), hace polling acotado de esa task y aplana
 * el `APIBasePreSaleResultDTO` a la shape neutra que consume el dominio.
 *
 * El dominio nunca importa esta clase (bind directo en AppServiceProvider). Los
 * mapeos Visred (defaults de catálogo, aplanado del pago, polling) viven SOLO
 * acá. Ver docs/v2/08 §6 y docs/v2/10 §3; contrato verificado en el ROADMAP.
 */
class VisredEmissionProvider implements EmissionProvider
{
    private const EMITIR_PATH = '/v1/patrimoniales/vehicles/emitir/';

    public function __construct(
        private readonly VisredClient $client,
        private readonly VisredInspectionService $inspection,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array{
     *     task_id: string,
     *     status: string,
     *     presale_id: int|null,
     *     proposal_number: string|null,
     *     policy_number: string|null,
     *     emission_status: string|null,
     *     requires_inspection_after_emission: bool,
     *     company_id: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function emit(array $request): array
    {
        $taskItem = $this->client->post(self::EMITIR_PATH, $this->buildRequest($request));
        $taskId = is_scalar($taskItem['task_id'] ?? null) ? (string) $taskItem['task_id'] : '';

        $result = $taskId === '' ? null : $this->pollEmission($taskId);

        return $this->mapResult($taskId, $taskItem, $result);
    }

    /**
     * Inspección post-emisión: arma las inspecciones desde las fotos R2 (vía el
     * servicio de inspección) y las sube al endpoint del presale. Delega toda la
     * traducción Visred (tipos requeridos, base64, mapeo de `photo_key`).
     *
     * @param  iterable<InspectionPhoto>  $photos
     * @return array<string, mixed>
     */
    public function uploadInspection(int $presaleId, string $companyId, string $productId, iterable $photos): array
    {
        $inspections = $this->inspection->buildInspections($companyId, $productId, $photos);

        if ($inspections === []) {
            return ['status' => 'SKIPPED', 'reason' => 'sin_inspecciones_resueltas'];
        }

        return $this->inspection->submitPostEmission($presaleId, $inspections);
    }

    /**
     * Request neutro (dominio) → `PreSaleVehicleRequest` (Visred). Ver docs/v2/08 §6.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function buildRequest(array $request): array
    {
        $holder = is_array($request['holder'] ?? null) ? $request['holder'] : [];
        $address = is_array($request['address'] ?? null) ? $request['address'] : [];
        $vehicle = is_array($request['vehicle'] ?? null) ? $request['vehicle'] : [];
        $payment = is_array($request['payment'] ?? null) ? $request['payment'] : [];

        $payload = [
            'quotation_result_id' => (int) ($request['quotation_result_ref'] ?? 0),
            'person_holder' => $this->buildHolder($holder),
            'address' => $this->buildAddress($address),
            'vehicle' => $this->buildVehicle($vehicle),
            'payment' => $this->buildPayment($payment),
        ];

        $discountId = $request['discount_id'] ?? null;
        if (is_scalar($discountId) && (string) $discountId !== '') {
            $payload['discount_id'] = (string) $discountId;
        }

        $inspections = $request['inspections'] ?? null;
        if (is_array($inspections) && $inspections !== []) {
            $payload['inspections'] = $this->buildInspections($inspections);
        } else {
            // Before-emisión (D4.1): el dominio pasó los ingredientes neutros; acá
            // se construyen las inspecciones (tipos requeridos + R2→base64 + mapeo
            // de photo_key, traducción Visred) y se embeben en el emit().
            $built = $this->buildBeforeEmissionInspections($request['inspection_photos'] ?? null);
            if ($built !== []) {
                $payload['inspections'] = $built;
            }
        }

        return $payload;
    }

    /**
     * Construye las inspecciones before-emisión desde el bloque neutro de
     * ingredientes (`{company_id, product_id, photos}`) que arma el dominio. Toda
     * la lógica Visred (qué tipos exige la compañía, descarga R2→base64, mapeo de
     * `photo_key`) vive en {@see VisredInspectionService}, no en el dominio.
     *
     * @return list<array{id: string, document_base64: string}>
     */
    private function buildBeforeEmissionInspections(mixed $inspectionPhotos): array
    {
        if (! is_array($inspectionPhotos)) {
            return [];
        }

        $companyId = is_scalar($inspectionPhotos['company_id'] ?? null) ? (string) $inspectionPhotos['company_id'] : '';
        $productId = is_scalar($inspectionPhotos['product_id'] ?? null) ? (string) $inspectionPhotos['product_id'] : 'auto';
        $photos = $inspectionPhotos['photos'] ?? null;

        if ($companyId === '' || ! is_iterable($photos)) {
            return [];
        }

        return $this->buildInspections($this->inspection->buildInspections($companyId, $productId, $photos));
    }

    /**
     * Titular. `person_type_id`/`document_type_id` defaultean al caso auto/consumidor
     * (física/DNI) — decisión de catálogo del proveedor, vive en el adapter. Los
     * campos ausentes no se envían (Visred 400 si falta uno requerido = deuda checkout).
     *
     * @param  array<string, mixed>  $holder
     * @return array<string, mixed>
     */
    private function buildHolder(array $holder): array
    {
        return array_filter([
            'document_number' => $holder['document_number'] ?? null,
            'document_type_id' => $holder['document_type_id'] ?? 'dni',
            'person_type_id' => $holder['person_type_id'] ?? 'fisica',
            'first_name' => $holder['first_name'] ?? null,
            'last_name' => $holder['last_name'] ?? null,
            'email' => $holder['email'] ?? null,
            'tax_condition_id' => $holder['tax_condition_id'] ?? null,
            'birthdate' => $holder['birthdate'] ?? null,
            'sex_id' => $holder['sex_id'] ?? null,
            'phone_prefix' => $holder['phone_prefix'] ?? null,
            'phone_number' => $holder['phone_number'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function buildAddress(array $address): array
    {
        return array_filter([
            'zip_code' => isset($address['zip_code']) ? (int) $address['zip_code'] : null,
            'street_name' => $address['street_name'] ?? null,
            'street_number' => $address['street_number'] ?? null,
            'floor' => $address['floor'] ?? null,
            'apartment' => $address['apartment'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    private function buildVehicle(array $vehicle): array
    {
        return array_filter([
            'plate' => $vehicle['plate'] ?? null,
            'motor' => $vehicle['motor'] ?? null,
            'chassis' => $vehicle['chassis'] ?? null,
            'has_gnc' => $vehicle['has_gnc'] ?? false,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Pago neutro (`{method, card:{...}, cbu}`) → campos planos de Visred. El
     * aplanado de la tarjeta es traducción del adapter (el dominio no conoce los
     * nombres `credit_card_*`).
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function buildPayment(array $payment): array
    {
        $out = ['payment_method_id' => $payment['method'] ?? 'tarjeta'];

        if (is_array($payment['card'] ?? null)) {
            $card = $payment['card'];
            $out += [
                'credit_card_brand_id' => $card['brand'] ?? null,
                'credit_card_holder' => $card['holder'] ?? null,
                'credit_card_number' => $card['number'] ?? null,
                'credit_card_expire_month' => isset($card['expire_month']) ? (int) $card['expire_month'] : null,
                'credit_card_expire_year' => isset($card['expire_year']) ? (int) $card['expire_year'] : null,
            ];
        }

        if (is_scalar($payment['cbu'] ?? null) && (string) $payment['cbu'] !== '') {
            $out['bank_cbu'] = (string) $payment['cbu'];
        }

        return array_filter($out, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Inspecciones neutras (`{type_id, image_base64}`) → shape Visred
     * (`{id, document_base64}`). La obtención de las fotos (R2→base64) es upstream
     * (scope inspección); acá solo se renombra al contrato del proveedor.
     *
     * @param  array<int, mixed>  $inspections
     * @return list<array{id: string, document_base64: string}>
     */
    private function buildInspections(array $inspections): array
    {
        $out = [];
        foreach ($inspections as $inspection) {
            if (! is_array($inspection)) {
                continue;
            }
            $id = is_scalar($inspection['type_id'] ?? null) ? (string) $inspection['type_id'] : '';
            $image = is_scalar($inspection['image_base64'] ?? null) ? (string) $inspection['image_base64'] : '';
            if ($id !== '' && $image !== '') {
                $out[] = ['id' => $id, 'document_base64' => $image];
            }
        }

        return $out;
    }

    /**
     * Polling acotado de la única task de emisión. Devuelve el
     * `APIBasePreSaleResultDTO` en SUCCESS, o null en FAILURE / budget agotado.
     *
     * @return array<string, mixed>|null
     */
    private function pollEmission(string $taskId): ?array
    {
        $budget = (int) config('visred.poll_budget', 120);
        $interval = max(1, (int) config('visred.poll_interval', 4));
        $elapsed = 0;

        while (true) {
            $task = $this->client->get("/v1/tasks/{$taskId}/");
            $status = is_string($task['status'] ?? null) ? $task['status'] : 'PENDING';

            if ($status === 'SUCCESS') {
                $result = $task['result'] ?? null;

                return is_array($result) ? $result : null;
            }

            if ($status === 'FAILURE') {
                Log::warning('[VisredEmission] Task de emisión con FAILURE', ['task_id' => $taskId]);

                return null;
            }

            if ($elapsed + $interval > $budget) {
                Log::warning('[VisredEmission] Budget de polling agotado sin terminal', ['task_id' => $taskId]);

                return null;
            }

            Sleep::for($interval)->seconds();
            $elapsed += $interval;
        }
    }

    /**
     * `APIBasePreSaleResultDTO` → shape neutra de MANGO. SUCCESS sii hay `presale_id`.
     *
     * @param  array<string, mixed>  $taskItem
     * @param  array<string, mixed>|null  $result
     * @return array{
     *     task_id: string,
     *     status: string,
     *     presale_id: int|null,
     *     proposal_number: string|null,
     *     policy_number: string|null,
     *     emission_status: string|null,
     *     requires_inspection_after_emission: bool,
     *     company_id: string|null,
     *     raw: array<string, mixed>
     * }
     */
    private function mapResult(string $taskId, array $taskItem, ?array $result): array
    {
        $presaleId = is_scalar($result['presale_id'] ?? null) ? (int) $result['presale_id'] : null;

        return [
            'task_id' => $taskId,
            'status' => $presaleId !== null ? 'SUCCESS' : 'FAILURE',
            'presale_id' => $presaleId,
            'proposal_number' => is_scalar($result['proposal_number'] ?? null) ? (string) $result['proposal_number'] : null,
            'policy_number' => is_scalar($result['policy_number'] ?? null) ? (string) $result['policy_number'] : null,
            'emission_status' => is_scalar($result['status'] ?? null) ? (string) $result['status'] : null,
            'requires_inspection_after_emission' => ($result['require_inspection_after_emission'] ?? false) === true,
            // company_id sale del TaskItem de emitir (lo usa la inspección post-emisión).
            'company_id' => is_scalar($taskItem['company_id'] ?? null) ? (string) $taskItem['company_id'] : null,
            'raw' => ['source' => 'Visred', 'emitir' => $taskItem, 'task' => $result],
        ];
    }
}
