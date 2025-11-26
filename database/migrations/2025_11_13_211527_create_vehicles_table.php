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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('patente')->nullable()->unique();
            $table->string('marca')->nullable(); // VW, Ford, Fiat, etc.
            $table->string('modelo')->nullable(); // Gol, Focus, Palio
            $table->string('version')->nullable(); // Trend, Titanium, etc.
            $table->integer('year')->nullable();
            $table->enum('combustible', ['nafta', 'diesel', 'gnc', 'electrico', 'hibrido'])->nullable();
            $table->string('codigo_postal')->nullable();
            $table->enum('uso', ['particular', 'comercial', 'taxi_remis', 'uber'])->default('particular');
            $table->string('motor')->nullable(); // Nro. de motor
            $table->string('chasis')->nullable(); // Nro de chasis
            $table->boolean('is_complete')->default(false); // Si tiene todos los datos
            $table->softDeletes(); // ← Soft deletes
            
            $table->json('metadata')->nullable(); // Datos adicionales
            $table->timestamps();
            
            $table->index('patente');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
