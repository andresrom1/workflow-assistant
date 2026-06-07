<?php

namespace Tests\Support;

use App\Contracts\Quotability;
use App\Models\Vehicle;
use App\Services\Quotability\QuotabilityResult;

/**
 * Doble de test del puerto {@see Quotability}.
 *
 * Por defecto devuelve `Quotable` sin tocar la red ni el LLM, para que los tests
 * que NO son de quotability (CRUD/NLU del vehículo, flujo del orquestador) no
 * peguen a Visred. Los tests específicos de quotability usan el resolver real
 * con `Http::fake` + `DisambiguationAgent::fake`.
 */
class StubQuotability implements Quotability
{
    public function check(Vehicle $vehicle): QuotabilityResult
    {
        // Quotable, conservando la versión que dijo el cliente (no refina).
        return QuotabilityResult::quotable($vehicle->version ?? 'STUB', 'stub', 'STUB_REF');
    }
}
