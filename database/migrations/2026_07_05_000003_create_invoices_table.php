<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comprobante fiscal (Factura C) emitido —o intentado— contra AFIP. Registro contable
     * INMUTABLE: los datos del receptor se snapshotean al crear la fila, así editar la
     * `billing_company` luego no reescribe la historia. `numero_comprobante`, `cae` y su
     * vencimiento los asigna AFIP al autorizar; `observaciones` guarda el error si rechaza.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('batch_id')->constrained('invoice_batches')->cascadeOnDelete();
            $table->foreignId('billing_company_id')->constrained('billing_companies')->restrictOnDelete();

            $table->decimal('importe', 15, 2);
            $table->unsignedSmallInteger('pto_vta');
            $table->unsignedSmallInteger('tipo_comprobante')->default(11); // Factura C
            $table->unsignedInteger('numero_comprobante')->nullable(); // lo asigna AFIP
            $table->string('codigo'); // snapshot del código del lote

            $table->string('cae')->nullable();
            $table->date('cae_vencimiento')->nullable();

            $table->date('fecha_comprobante');
            $table->date('fecha_servicio_desde');
            $table->date('fecha_servicio_hasta');
            $table->date('fecha_vto_pago');

            // Snapshot inmutable del receptor.
            $table->string('receptor_razon_social');
            $table->string('receptor_cuit');
            $table->string('receptor_condicion_iva');

            $table->string('pdf_path')->nullable();
            $table->string('estado')->default('pending'); // InvoiceEstado
            $table->text('observaciones')->nullable(); // error de AFIP en rechazo

            $table->timestamps();

            $table->index('estado');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
