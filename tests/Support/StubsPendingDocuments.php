<?php

namespace Tests\Support;

/**
 * Implementación trivial de `EmissionProvider::capturePendingDocuments()` para los
 * dobles de test que sólo ejercitan `emit()`. La captura diferida real (con polling y
 * reintento) se cubre en VisredDocumentService/Provider y en el test del job.
 */
trait StubsPendingDocuments
{
    public function capturePendingDocuments(string $documentToken, string $productId, array $kinds): array
    {
        return [];
    }
}
