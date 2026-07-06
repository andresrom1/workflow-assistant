<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domicilio de la compañía receptora (para el bloque del receptor en la factura) + su
     * snapshot inmutable en la factura, igual que el resto de los datos del receptor.
     */
    public function up(): void
    {
        Schema::table('billing_companies', function (Blueprint $table): void {
            $table->string('domicilio')->nullable()->after('condicion_iva');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('receptor_domicilio')->nullable()->after('receptor_condicion_iva');
        });
    }

    public function down(): void
    {
        Schema::table('billing_companies', function (Blueprint $table): void {
            $table->dropColumn('domicilio');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('receptor_domicilio');
        });
    }
};
