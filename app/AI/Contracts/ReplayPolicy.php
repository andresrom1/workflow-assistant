<?php

namespace App\AI\Contracts;

/**
 * Indica cómo debe comportarse una Tool durante un replay del Studio (Fase 6).
 *
 * - REAL: ejecuta su lógica normal. Apto para tools puramente de lectura o
 *         que no tienen efectos colaterales reversibles (RAG, búsquedas).
 * - MOCK: retorna una respuesta canned plausible sin persistir nada ni
 *         llamar al canal externo. Apto para tools que escriben en DB,
 *         mutan ai_state, dispatchean jobs o envían mensajes.
 */
enum ReplayPolicy: string
{
    case REAL = 'real';
    case MOCK = 'mock';
}
