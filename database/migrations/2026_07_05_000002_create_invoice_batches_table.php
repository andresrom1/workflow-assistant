<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lote de facturación mensual: agrupa las Facturas C emitidas en una corrida.
     *
     * Datos comunes a todas las facturas del lote (código, concepto, período de servicios,
     * vencimiento de pago). El lote arranca `processing` y un job lo cierra en `completed`
     * cuando terminó de procesar todas sus facturas contra AFIP; `summary` guarda los conteos
     * (autorizadas/rechazadas/total). El front hace polling hasta `finished_at`.
     */
    public function up(): void
    {
        Schema::create('invoice_batches', function (Blueprint $table): void {
            $table->id();

            $table->string('codigo'); // referencia interna del lote (ej. "0006")
            $table->string('concepto'); // "Comisiones correspondientes a Junio 2026"
            $table->unsignedSmallInteger('punto_venta');

            $table->date('fecha_comprobante');
            $table->date('fecha_servicio_desde');
            $table->date('fecha_servicio_hasta');
            $table->date('fecha_vto_pago');

            $table->string('estado')->default('processing'); // processing | completed

            // Conteos de la corrida: {autorizadas, rechazadas, total}.
            $table->jsonb('summary')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_batches');
    }
};
