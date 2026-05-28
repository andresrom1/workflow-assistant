<?php

namespace App\AI\Contracts;

use Laravel\Ai\Tools\Request;

/**
 * Contrato obligatorio para Tools con replayPolicy() = MOCK.
 *
 * En modo replay (cuando app()->bound('ai.replay_mode') es true), la tool
 * debe devolver una respuesta fabricada en lugar de ejecutar su lógica real.
 * Esto garantiza que el Studio pueda reevaluar turns con un draft de prompt
 * sin efectos colaterales en DB, WhatsApp, quotes, etc.
 */
interface Mockable
{
    /**
     * Respuesta canned que la tool devolvería en su handle() real,
     * pero sin persistir nada ni llamar a adapters.
     */
    public function mockResponse(Request $request): string;
}
