<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retiro del flujo legacy pas_mobile (app PAS de oportunidades).
 * Dropea la tabla de sincronización y el tracking mobile de las quotes.
 * Se conserva `resolution_method` (lo usa la estrategia de API).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mobile_sync_logs');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['mobile_opportunity_id']);
            $table->dropColumn([
                'mobile_opportunity_id',
                'mobile_reference',
                'sent_to_mobile_at',
                'expected_resolution_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('mobile_opportunity_id')->nullable()->after('resolution_method');
            $table->string('mobile_reference')->nullable()->after('mobile_opportunity_id');
            $table->timestamp('sent_to_mobile_at')->nullable();
            $table->timestamp('expected_resolution_at')->nullable();
            $table->index('mobile_opportunity_id');
        });

        Schema::create('mobile_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->onDelete('cascade');
            $table->string('opportunity_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->json('response_data')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();

            $table->index(['quote_id', 'status']);
            $table->index('opportunity_id');
        });
    }
};
