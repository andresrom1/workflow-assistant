<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fila estacionada de un reporte de cartera, dentro de un `policy_report_batches`.
     *
     * Read-only en la revisión: se confirma/descarta el lote entero (corregir = arreglar
     * la fuente y re-subir). Las columnas denormalizadas son para listar el diff sin abrir
     * el `payload` (fila cruda neutra, fuente de verdad). `accion` y `matched_poliza_id`
     * los calcula el dry-run del import; `nota` explica las `exception`.
     */
    public function up(): void
    {
        Schema::create('policy_report_rows', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('batch_id')->constrained('policy_report_batches')->cascadeOnDelete();

            // Denormalizado para listar el diff.
            $table->string('asegurado')->nullable();
            $table->string('documento')->nullable();
            $table->string('numero')->nullable();
            $table->string('company')->nullable();
            $table->string('producto')->nullable();
            $table->string('patente')->nullable();
            $table->string('estado_origen')->nullable();  // string crudo del reporte ("No-Vigente")
            $table->string('estado_mapeado')->nullable();  // PolizaEstado::value
            $table->date('vigencia')->nullable();

            // create | update_estado | noop | exception (calculado en el dry-run).
            $table->string('accion');
            $table->foreignId('matched_poliza_id')->nullable()->constrained('polizas')->nullOnDelete();
            $table->string('nota')->nullable(); // motivo de exception

            // Fila cruda normalizada por el parser (fuente de verdad).
            $table->jsonb('payload');

            $table->timestamps();

            $table->index('batch_id');
            $table->index('accion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_report_rows');
    }
};
