<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `numero_reservado` es el número que se le VA a pedir a AFIP, escrito antes de llamar. Existe para
 * cerrar la ventana entre la autorización y su persistencia: si el proceso muere en el medio, el
 * número queda anclado a la factura y el próximo intento puede consultar ESE comprobante contra
 * AFIP en vez de asumir hacia adelante — que fue lo que duplicó una factura el 2026-08-04.
 *
 * Los dos índices únicos son la defensa que no depende de que el código se comporte: un mismo
 * número no puede quedar reservado ni autorizado por dos comprobantes del mismo punto de venta.
 * En Postgres los NULL no colisionan entre sí, así que no hace falta un índice parcial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedInteger('numero_reservado')->nullable()->after('numero_comprobante');

            $table->unique(['pto_vta', 'tipo_comprobante', 'numero_reservado'], 'invoices_reservado_unique');
            $table->unique(['pto_vta', 'tipo_comprobante', 'numero_comprobante'], 'invoices_comprobante_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_reservado_unique');
            $table->dropUnique('invoices_comprobante_unique');
            $table->dropColumn('numero_reservado');
        });
    }
};
