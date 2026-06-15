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
     * Inspección (D4.1): el dominio NO arma las inspecciones (eso requiere
     * conocimiento del proveedor: tipos requeridos, mapeo de `photo_key`, base64).
     * Pasa los ingredientes neutros en `inspection_photos` (company_id opaco +
     * product_id + fotos de dominio + `requires_before`) y el adapter resuelve TODO
     * el ciclo de inspección INTERNAMENTE: si `requires_before` las embebe en el
     * `emit()`; si la emisión exige inspección post-emisión, la sube él mismo (el
     * `presale_id` no sale del adapter). `inspections` (pre-armadas) sigue soportado
     * para llamadas que ya las tengan.
     *
     * Documentos: el adapter captura los documentos oficiales dentro de la ventana
     * de emisión (con el `presale_id` vivo, internamente) y los devuelve como blobs
     * NEUTROS en `documents` para que el dominio los persista. No expone `presale_id`.
     *
     * @param  array{
     *     quotation_result_ref: string,
     *     holder: array<string, mixed>,
     *     address?: array<string, mixed>,
     *     vehicle: array<string, mixed>,
     *     payment?: array<string, mixed>,
     *     inspections?: list<array{type_id: string, image_base64: string}>,
     *     inspection_photos?: array{company_id: string, product_id: string, requires_before: bool, photos: iterable<InspectionPhoto>},
     *     discount_id?: string|null
     * }  $request
     * @return array{
     *     task_id: string,
     *     status: string,
     *     proposal_number: string|null,
     *     policy_number: string|null,
     *     emission_status: string|null,
     *     requires_inspection_after_emission: bool,
     *     company_id: string|null,
     *     documents: list<array{kind: string, filename: string, mime: string, contents: string}>,
     *     raw: array<string, mixed>
     * }
     */
    public function emit(array $request): array;
}
