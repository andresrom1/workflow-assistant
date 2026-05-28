<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT conversations_status_check');
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('anonymous', 'identified', 'active', 'completed', 'abandoned', 'archived'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT conversations_status_check');
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('anonymous', 'identified', 'active', 'completed', 'abandoned'))");
    }
};
