<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-keyea la memoria del AI SDK de `external_conversation_id` (teléfono, mutable) a
 * `conversations.id` (id interno, estable). Preserva los hilos vivos: cada
 * `agent_conversations.user_id` que hoy contiene el teléfono pasa al id de su conversación.
 *
 * Idempotente: solo matchea filas cuyo `user_id` todavía es el teléfono. Las reseteadas
 * (`user_id IS NULL`) y las archivadas (`external_conversation_id = 'archived_N'`) no matchean.
 * Rollback = restore desde backup (ver Fase 0 del plan); no es reversible de forma limpia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE agent_conversations ac
            SET user_id = c.id
            FROM conversations c
            WHERE ac.user_id IS NOT NULL
              AND ac.user_id::text = c.external_conversation_id
        SQL);
    }

    public function down(): void
    {
        // Data backfill no reversible de forma segura (la relación teléfono→id se pierde).
        // El rollback del re-keyeo se hace por restore de backup (Fase 0).
    }
};
