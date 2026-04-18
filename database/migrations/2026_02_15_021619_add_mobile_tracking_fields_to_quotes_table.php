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
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('resolution_method')->nullable()->after('status'); // 'api', 'mobile'
            $table->string('mobile_opportunity_id')->nullable()->after('resolution_method');
            $table->string('mobile_reference')->nullable()->after('mobile_opportunity_id');
            $table->timestamp('sent_to_mobile_at')->nullable();
            $table->timestamp('expected_resolution_at')->nullable();

            $table->index('mobile_opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn([
                'resolution_method',
                'mobile_opportunity_id',
                'mobile_reference',
                'sent_to_mobile_at',
                'expected_resolution_at',
            ]);
        });
    }
};
