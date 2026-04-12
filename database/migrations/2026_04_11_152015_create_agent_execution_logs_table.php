<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('agent_name');                      // 'CustomerIdentifierAgent'
            $table->unsignedTinyInteger('step');               // 1=customer … 5=checkout
            $table->json('state_before');
            $table->json('state_after');
            $table->json('state_changes');                     // solo los flags que flipearon
            $table->boolean('chained')->default(false);        // QuoteAgent→CheckoutAgent en un turno
            $table->string('status', 20)->default('success');  // success | error
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms');

            // Vínculos a mensajes — se populan de forma async por los jobs
            $table->json('inbound_message_ids')->nullable();   // IDs de los mensajes del usuario que dispararon esta ejecución
            $table->foreignId('outbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();

            // Tokens — nullable, depende de si el SDK los expone
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_execution_logs');
    }
};
