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
            $table->json('flags')->nullable()->after('metadata');
            $table->unsignedInteger('turns_in_current_step')->default(0)->after('flags');
            $table->timestamp('last_health_analysis_at')->nullable()->after('turns_in_current_step');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['flags', 'turns_in_current_step', 'last_health_analysis_at']);
        });
    }
};
