<?php

namespace App\AI\Concerns;

use App\AI\Contracts\Mockable;
use App\AI\Contracts\ReplayPolicy;
use Laravel\Ai\Tools\Request;

/**
 * Boilerplate para tools MOCK: declara la policy y expone un guard que se
 * llama al tope de handle() para cortocircuitar la ejecución real durante
 * un replay del Studio.
 *
 * Ejemplo de uso:
 *
 *     public function handle(Request $request): string
 *     {
 *         if (($mock = $this->interceptIfReplay($request)) !== null) {
 *             return $mock;
 *         }
 *         // ... lógica normal que persiste / llama adapters
 *     }
 *
 * @phpstan-require-implements Mockable
 */
trait HasMockReplay
{
    public function replayPolicy(): ReplayPolicy
    {
        return ReplayPolicy::MOCK;
    }

    /**
     * Si estamos en modo replay, devuelve la respuesta mockeada.
     * Caso contrario devuelve null y la tool continúa con su lógica real.
     *
     * La Tool debe implementar \App\AI\Contracts\Mockable::mockResponse().
     */
    protected function interceptIfReplay(Request $request): ?string
    {
        if (! app()->bound('ai.replay_mode')) {
            return null;
        }

        return $this->mockResponse($request);
    }
}
