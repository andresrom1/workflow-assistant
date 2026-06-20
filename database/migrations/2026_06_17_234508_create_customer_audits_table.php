<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rastro de auditoría de los cambios al registro canónico `Customer`. Cada fila es
     * un cambio efectivo de un campo, con su fuente (`admin`/`checkout`/`chat`). El
     * admin queda con `user_id`; checkout/chat con `user_id` null. Ver docs/v2/11.
     */
    public function up(): void
    {
        Schema::create('customer_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_audits');
    }
};
