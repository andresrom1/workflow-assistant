<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Póliza emitida sobre un Risk.
     *
     * Vehículo↔póliza es 1:N temporal (spec v2 §5): un Risk puede tener una
     * vigente + una emitida (renovación) + N vencidas históricas.
     * `sum_asegurada` vive acá (no en risks): puede actualizarse en cada
     * renovación.
     *
     * `metadata` JSONB queda como extensión para atributos no-comunes
     * (percepciones, recargos, condiciones particulares de la cía, etc.).
     *
     * Constraint "una sola vigente por Risk": en código, no en DB. Implementar
     * con índice parcial PG-specific complica testing en SQLite; validación
     * vive en el service que emita/renueve.
     */
    public function up(): void
    {
        Schema::create('polizas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->string('estado'); // PolizaEstado enum
            $table->string('numero')->nullable();
            $table->string('company');
            $table->string('coverage');
            $table->string('coverage_detail')->nullable();
            $table->decimal('sum_asegurada', 14, 2);
            $table->decimal('cuota', 12, 2)->nullable();
            $table->date('cuota_due')->nullable();
            $table->date('vigencia');
            $table->date('emitida_en')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['risk_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas');
    }
};
