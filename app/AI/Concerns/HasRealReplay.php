<?php

namespace App\AI\Concerns;

use App\AI\Contracts\ReplayPolicy;

/**
 * Boilerplate para tools REAL: tools puras de lectura (RAG, búsquedas)
 * que no tienen efectos colaterales y pueden correr durante un replay.
 */
trait HasRealReplay
{
    public function replayPolicy(): ReplayPolicy
    {
        return ReplayPolicy::REAL;
    }
}
