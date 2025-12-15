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
        // TABLA 2: QUOTE_ALTERNATIVES (ITEMS)
        // Objetivo: Normalización y Venta.
        Schema::create('quote_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->onDelete('cascade');
            
            // --- DATOS DUROS (SQL Columns) ---
            // Necesarios para ordenar, filtrar y contratar.
            
            // ID para comprar (SKU)
            $table->string('external_code')->index(); 
            
            // ID Externo de la opción específica (El "data-cover" del HTML/JSON)
            // CRÍTICO: Este es el ID que se manda a emitir.
            $table->string('external_quote_id')->index(); 
            
            // Datos visuales principales
            $table->string('aseguradora'); // Ej: Triunfo
            $table->string('descripcion');  // Ej: "C1 - Terceros Completos"
            $table->string('titulo')->nullable(); // Ej: "C1"
            
            // LA COLUMNA MÁS IMPORTANTE PARA LA IA:

            // Normalización (Tu lógica de 'normalized_grade')
            // Valores: 'liability', 'basic', 'third_party_complete', 'all_risk'
            $table->string('normalized_grade')->index(); 
            
            // Datos Financieros (Decimal siempre para dinero)
            $table->decimal('precio', 12, 2)->index();
            $table->string('moneda', 3)->default('ARS');
            
            // --- DATOS BLANDOS (JSON Columns) ---
            // Flexibilidad para atributos variables.
            
            // Títulos Comerciales (Para mostrar en UI)
            // Ej: "Pack Ahorro Plus"
            $table->string('marketing_title')->nullable();
            
             // Detalles de cobertura extraídos del Modal
            $table->string('sum_insured_text')->nullable(); // Ej: "$ 9.600.000,00"
            
            // Almacena flags booleanos extraídos (Ej: { "has_hail": true, "has_wheels": true })
            // Útil para filtros rápidos en el frontend/chatbot
            $table->json('features_tags')->nullable(); 

            // Almacena el texto completo de beneficios para mostrar en "Ver más"
            // Se usa JSON porque el modelo lo tiene en $casts => array
            $table->json('full_details')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_alternatives');
    }
};
