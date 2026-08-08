<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visred cotiza el mismo cover una vez por medio de pago. San Cristóbal manda tres
 * (`cbu`, `tarjeta`, `cupon`) y el cupón sale ~22% más caro que los otros dos; sin este
 * campo las filas quedan indistinguibles y el cupón entra a la presentación como si fuera
 * otro producto. Nullable: las cotizaciones ya guardadas no lo tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_alternatives', function (Blueprint $table): void {
            $table->string('payment_method_id')->nullable()->after('moneda');
        });
    }

    public function down(): void
    {
        Schema::table('quote_alternatives', function (Blueprint $table): void {
            $table->dropColumn('payment_method_id');
        });
    }
};
