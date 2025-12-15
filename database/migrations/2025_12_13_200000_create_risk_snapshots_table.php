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
        Schema::create('risk_snapshots', function (Blueprint $table) {
            $table->id();

            // Relaciones (Vínculos blandos o duros)
            // Usamos nullable en vehicle_id por si el vehículo se borra físicamente, 
            // aunque el snapshot debe persistir.
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // --- SNAPSHOT DEL VEHÍCULO (Copia dura de la tabla vehicles) ---
            // Guardamos estos datos TEXTUALMENTE para evitar que un cambio 
            // en la tabla vehicles (ej: corrección de modelo) corrompa esta cotización.
            $table->string('marca'); // Ej: Fiat
            $table->string('modelo'); // Ej: Cronos
            $table->string('version'); // Ej: 1.3 Drive
            $table->integer('year');   // Ej: 2023

            // --- FACTORES DE RIESGO (Variables críticas de precio) ---
            $table->string('combustible'); // ¿Tenía GNC en ese momento?
            $table->string('uso'); // Particular, Comercial, Uber
            $table->string('codigo_postal'); // El CP determinante del riesgo

            // --- SNAPSHOT DEL CONDUCTOR ---
            // Datos del conductor que afectan el precio (ej: Edad)
            $table->string('dni')->nullable();
            $table->date('edad_conductor')->nullable();

            // Auditoría
            $table->timestamps(); // created_at es la fecha del snapshot
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_snapshots');
    }
};
