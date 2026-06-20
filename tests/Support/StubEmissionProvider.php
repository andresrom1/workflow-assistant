<?php

namespace Tests\Support;

use App\Contracts\EmissionProvider;

/**
 * Doble de test del puerto {@see EmissionProvider}.
 *
 * Devuelve un resultado de emisión determinístico sin tocar la red, para que los
 * tests que NO son de emisión Visred no peguen a la API. Los tests del path real
 * usan VisredEmissionProvider con Http::fake. Espeja {@see StubQuotationProvider}.
 */
class StubEmissionProvider implements EmissionProvider
{
    public function emit(array $request): array
    {
        return [
            'task_id' => 'stub-emit-task',
            'status' => 'SUCCESS',
            'proposal_number' => 'PROP-STUB-1',
            'policy_number' => 'POL-STUB-1',
            'emission_status' => 'emitida',
            'requires_inspection_after_emission' => false,
            'company_id' => 'stub-company',
            'documents' => [],
            'pending_documents' => ['token' => '', 'product_id' => 'auto', 'kinds' => []],
            'raw' => ['source' => 'StubEmissionProvider'],
        ];
    }

    public function capturePendingDocuments(string $documentToken, string $productId, array $kinds): array
    {
        return [];
    }
}
