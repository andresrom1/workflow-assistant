<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lote de import de un reporte de cartera (snapshot de pólizas) subido al panel.
     *
     * Modo de arranque (igual que la ingesta de PDFs): nada se materializa al subir. El
     * lote arranca `pendiente` con sus filas estacionadas y un `summary` (conteos del
     * dry-run); al confirmar el admin se materializa la cadena `Customer→Risk→Poliza` y
     * pasa a `confirmado`; si lo rechaza, `descartado`.
     *
     * Idempotencia: `hash_sha256` único del archivo — re-subir el mismo reporte no duplica.
     * El `origen` elige el parser y queda como provenance; los orígenes no colisionan.
     */
    public function up(): void
    {
        Schema::create('policy_report_batches', function (Blueprint $table): void {
            $table->id();

            $table->string('origen'); // ReporteOrigen (portal_visred, ...)
            $table->string('original_filename')->nullable();
            $table->string('hash_sha256')->unique(); // dedup de re-subida
            $table->string('status')->default('pendiente'); // pendiente | confirmado | descartado

            // Conteos del dry-run: {create, update_estado, noop, exception, total}.
            $table->jsonb('summary')->nullable();

            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('origen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_report_batches');
    }
};
