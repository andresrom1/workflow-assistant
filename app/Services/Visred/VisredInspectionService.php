<?php

namespace App\Services\Visred;

use App\Models\InspectionPhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Traducción de la inspección de vehículo al contrato Visred.
 *
 * Responsabilidades (todas específicas del proveedor → viven en el adapter):
 *  - Consultar qué tipos de inspección exige una compañía/producto (dato vivo).
 *  - Descargar las fotos del checkout de R2 y codificarlas a base64.
 *  - Mapear el `photo_key` de mango → `inspection_type_id` de Visred (config
 *    verificada contra el sandbox), enviando solo los tipos que tenemos foto.
 *  - Subir la inspección post-emisión.
 *
 * El dominio nunca importa esta clase: la dispara el adapter de emisión a través
 * del puerto. Ver docs/v2/08 §6 y el ROADMAP (Pieza E).
 */
class VisredInspectionService
{
    private const TYPES_PATH = '/v1/patrimoniales/vehicles/params/inspection-types/';

    public function __construct(private readonly VisredClient $client) {}

    /**
     * Ids de los tipos de inspección que la compañía/producto exige (en vivo).
     *
     * @return list<string>
     */
    public function requiredTypeIds(string $companyId, string $productId): array
    {
        $types = $this->client->get(self::TYPES_PATH, [
            'company_id' => $companyId,
            'product_id' => $productId,
        ]);

        $ids = [];
        foreach ($types as $type) {
            $id = is_array($type) ? ($type['id'] ?? null) : null;
            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Inspecciones neutras (`{type_id, image_base64}`) para los tipos requeridos
     * que tenemos foto. Loggea los requeridos sin captura (gap de checkout).
     *
     * @param  iterable<InspectionPhoto>  $photos
     * @return list<array{type_id: string, image_base64: string}>
     */
    public function buildInspections(string $companyId, string $productId, iterable $photos): array
    {
        $required = $this->requiredTypeIds($companyId, $productId);

        /** @var array<string, string> $map  photo_key → inspection_type_id */
        $map = (array) config('visred.inspection_photo_map', []);
        $typeToKey = array_flip($map);

        $photoByKey = [];
        foreach ($photos as $photo) {
            $photoByKey[$photo->photo_key] = $photo;
        }

        $inspections = [];
        foreach ($required as $typeId) {
            $key = $typeToKey[$typeId] ?? null;
            $photo = is_string($key) ? ($photoByKey[$key] ?? null) : null;

            if (! $photo instanceof InspectionPhoto) {
                Log::warning('[VisredInspection] Tipo requerido sin foto capturada (gap de checkout)', [
                    'company_id' => $companyId,
                    'type_id' => $typeId,
                ]);

                continue;
            }

            $bytes = Storage::disk('r2')->get($photo->storage_path);
            if (! is_string($bytes) || $bytes === '') {
                Log::warning('[VisredInspection] Foto no encontrada en R2', ['path' => $photo->storage_path]);

                continue;
            }

            $inspections[] = ['type_id' => $typeId, 'image_base64' => base64_encode($bytes)];
        }

        return $inspections;
    }

    /**
     * Sube la inspección post-emisión: `POST emitir/{presale_id}/inspeccion/`.
     *
     * @param  list<array{type_id: string, image_base64: string}>  $inspections
     * @return array<string, mixed>
     */
    public function submitPostEmission(int $presaleId, array $inspections): array
    {
        $payload = [
            'inspections' => array_map(
                fn (array $inspection): array => [
                    'id' => $inspection['type_id'],
                    'document_base64' => $inspection['image_base64'],
                ],
                $inspections,
            ),
        ];

        return $this->client->post("/v1/patrimoniales/vehicles/emitir/{$presaleId}/inspeccion/", $payload);
    }
}
