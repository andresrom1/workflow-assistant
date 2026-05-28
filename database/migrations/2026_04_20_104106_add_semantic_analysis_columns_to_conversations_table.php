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
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('semantic_analysis')->nullable()->after('last_health_analysis_at');
            $table->timestamp('last_semantic_analysis_at')->nullable()->after('semantic_analysis');
            $table->unsignedInteger('semantic_analysis_turn_count')->default(0)->after('last_semantic_analysis_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'semantic_analysis',
                'last_semantic_analysis_at',
                'semantic_analysis_turn_count',
            ]);
        });
    }
};
