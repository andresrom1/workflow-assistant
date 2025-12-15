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
        // TABLA 1: QUOTES (HEADER)
        // Objetivo: Trazabilidad, Estado y Auditoría.
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            
            // Contexto del Negocio (Vínculos Fuertes)
            // Relación con el Snapshot (1 a N, aunque usualmente 1 a 1 por llamada)
            $table->foreignId('risk_snapshot_id')->constrained('risk_snapshots')->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained()->index();
            
            // Estado del Proceso (State Machine)
            // 'pending': Enviado al proveedor.
            // 'processed': Respuesta recibida y parseada correctamente.
            // 'failed': Error técnico o de negocio.
            // 'expired': Cotización vieja (precios no válidos).
            $table->string('status')->default('pending')->index();
            
            // Identificador Externo (Correlación)
            // El ID que te devuelve el proveedor (ej: "task_id", "quote_uuid").
            $table->string('external_ref_id')->nullable()->index();
            
            // La "Caja Negra" (Auditoría)
            // Guardamos SIEMPRE el JSON original completo tal cual llegó.
            // Vital para debug y disputas legales sobre coberturas.
            $table->json('raw_response')->nullable();

            // Metadatos extra del proveedor (ej: tiempo de respuesta)
            $table->json('metadata')->nullable();
            
            // Metadatos de Tiempo
            $table->timestamp('expires_at')->nullable(); // Cuándo vence el precio
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
