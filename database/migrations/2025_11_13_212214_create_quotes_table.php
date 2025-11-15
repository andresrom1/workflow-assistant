<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('vehicle_id')->constrained()->onDelete('cascade'); // ← El vehículo de ESTA cotización
            $table->string('coverage_type');
            $table->enum('status', ['pending_pricing', 'priced', 'accepted', 'rejected', 'expired'])->default('pending_pricing');
            
            // Datos relevados por el agent
            $table->json('vehicle_data')->nullable();
            $table->json('coverage_data')->nullable();
            $table->json('customer_preferences')->nullable();
            
            // Datos cotizados por humano
            $table->decimal('premium_amount', 10, 2)->nullable();
            $table->string('payment_frequency')->nullable();
            $table->decimal('suma_asegurada', 12, 2)->nullable();
            $table->text('special_conditions')->nullable();
            $table->decimal('discount_applied', 5, 2)->default(0);
            $table->decimal('final_price', 10, 2)->nullable();
            $table->foreignId('priced_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('priced_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
            $table->index('customer_id');
            $table->index('vehicle_id');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};