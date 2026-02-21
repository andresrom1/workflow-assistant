<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mobile_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->onDelete('cascade');
            $table->string('opportunity_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->json('response_data')->nullable();
            $table->string('status'); // success, failed, webhook_received
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();

            $table->index(['quote_id', 'status']);
            $table->index('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_sync_logs');
    }
};
