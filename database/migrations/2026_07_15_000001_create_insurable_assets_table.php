<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurable_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            // Identidad derivada por tipo (AssetType::naturalKey). Índice NO único a
            // propósito: datos preexistentes pueden colisionar tras normalizar (mismo
            // criterio que customers.documento_key).
            $table->string('natural_key')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'type', 'natural_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurable_assets');
    }
};
