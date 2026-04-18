<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_prompts', function (Blueprint $table) {
            $table->enum('type', ['agent', 'shared'])->default('agent')->after('agent_key');
            $table->index(['type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_prompts', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_active']);
            $table->dropColumn('type');
        });
    }
};
