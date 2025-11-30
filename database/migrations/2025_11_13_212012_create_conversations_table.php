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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('external_conversation_id')->unique(); // ID externo (thread_id) agnostico
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('status', ['anonymous', 'identified', 'active', 'completed', 'abandoned'])->default('anonymous');
            $table->json('metadata')->nullable();
            $table->string('external_user_id')->nullable(); // ID del dueno del hilo agnostico
            $table->timestamps();
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->softDeletes(); // ← Soft deletes
            
            $table->index('external_conversation_id');
            $table->index('customer_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
