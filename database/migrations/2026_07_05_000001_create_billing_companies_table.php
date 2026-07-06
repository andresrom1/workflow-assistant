<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compañías a las que el productor factura sus comisiones (receptores de las Facturas C).
     *
     * NO es el catálogo de aseguradoras de Visred: acá conviven compañías del catálogo con
     * otras ajenas (Cooperación, LPS, …). Es el padrón fiscal propio del módulo de facturación,
     * desacoplado del dominio de pólizas.
     */
    public function up(): void
    {
        Schema::create('billing_companies', function (Blueprint $table): void {
            $table->id();

            $table->string('razon_social');
            $table->string('cuit')->unique(); // 11 dígitos, sin guiones
            $table->string('condicion_iva')->default('RI'); // hoy todas Responsable Inscripto
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_companies');
    }
};
