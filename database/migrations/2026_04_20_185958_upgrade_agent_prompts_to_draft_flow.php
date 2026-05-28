<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_prompts', function (Blueprint $table) {
            // 'active' | 'draft' | 'archived'. Varchar para evitar enum nativo de Postgres.
            $table->string('status', 16)->default('archived')->after('is_active');
            $table->foreignId('owner_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('parent_version_id')->nullable()->after('owner_id')
                ->constrained('agent_prompts')->nullOnDelete();

            $table->index(['agent_key', 'status']);
        });

        // Sincroniza status con la bandera is_active existente (Fase 4 preserva compat).
        DB::table('agent_prompts')
            ->where('is_active', true)
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('agent_prompts', function (Blueprint $table) {
            $table->dropForeign(['parent_version_id']);
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['agent_key', 'status']);
            $table->dropColumn(['status', 'owner_id', 'parent_version_id']);
        });
    }
};
