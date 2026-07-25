<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ancla la conversación en el BSUID (`ext_user_id`, identidad estable del canal) en vez del
 * teléfono. `external_conversation_id` (vestigio OpenAI, mutable) pasa a nullable y deja de
 * escribirse (se dropea en Fase 5). Índice único parcial: una conversación activa por BSUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE conversations ALTER COLUMN external_conversation_id DROP NOT NULL');

        // Una sola conversación viva por BSUID (excluye archivadas y soft-deleted; los NULL
        // son distintos entre sí en Postgres, así que no colisionan).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conversations_ext_user_id_active_unique
            ON conversations (ext_user_id)
            WHERE status != 'archived' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conversations_ext_user_id_active_unique');
        // No se re-impone NOT NULL: podría haber filas nuevas con external_conversation_id NULL.
    }
};
