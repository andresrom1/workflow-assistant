<?php

namespace App\Contracts;

use App\Models\InspectionPhoto;

/**
 * Puerto de emisión — agnóstico de proveedor.
 *
 * El dominio (PolizaEmisionService) depende de esta interface, NO de un proveedor
 * concreto. Implementación de producción: VisredEmissionProvider (real siempre,
 * bind directo en AppServiceProvider). En tests: StubEmissionProvider. Espeja
 * {@see QuotationProvider}: entrada y salida son shapes neutras de MANGO; el
 * adapter traduce a/desde el contrato Visred. El dominio nunca importa una clase
 * de Visred. Ver docs/v2/10 §2/§3.
 */
interface EmissionProvider
{
    /**
     * Emite la póliza para la cobertura elegida. NO escribe en base de datos:
     * solo dispara la emisión contra el proveedor y devuelve el resultado.
     *
     * `$request` es la shape neutra que arma el dominio (no menciona campos de
     * Visred). El adapter la mapea a `PreSaleVehicleRequest`. El titular se asume
     * ya completo (capturar lo faltante es scope checkout).
     *
     * Inspección before-emisión (D4.1): cuando la cobertura elegida la exige, el
     * dominio NO arma las inspecciones (eso requiere conocimiento del proveedor:
     * tipos requeridos, mapeo de `photo_key`, base64). Pasa los ingredientes neutros
     * en `inspection_photos` (company_id opaco + product_id + fotos de dominio) y el
     * adapter las construye y embebe en el `emit()`. `inspections` (pre-armadas) sigue
     * soportado para llamadas que ya las tengan.
     *
     * @param  array{
     *     quotation_result_ref: string,
     *     holder: array<string, mixed>,
     *     address?: array<string, mixed>,
     *     vehicle: array<string, mixed>,
     *     payment?: array<string, mixed>,
     *     inspections?: list<array{type_id: string, image_base64: string}>,
     *     inspection_photos?: array{company_id: string, product_id: string, photos: iterable<InspectionPhoto>},
     *     discount_id?: string|null
     * }  $request
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
    public function emit(array $request): array;

    /**
     * Sube la inspección post-emisión cuando la compañía la exige
     * (`requires_inspection_after_emission`). Recibe las fotos de dominio del
     * checkout; el adapter consulta los tipos requeridos, las codifica y las
     * traduce al contrato del proveedor. NO escribe en base de datos.
     *
     * @param  iterable<InspectionPhoto>  $photos
     * @return array<string, mixed>
     */
    public function uploadInspection(int $presaleId, string $companyId, string $productId, iterable $photos): array;
}
