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
        Schema::table('agent_execution_logs', function (Blueprint $table) {
            $table->json('tool_calls')->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_execution_logs', function (Blueprint $table) {
            $table->dropColumn('tool_calls');
        });
    }
};
