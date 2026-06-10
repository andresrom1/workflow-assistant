<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suma asegurada numérica de la alternativa cotizada. Hasta ahora solo se
     * guardaba formateada en `sum_insured_text`; la cartera (Poliza.sum_asegurada)
     * necesita el valor numérico de Visred (`insured_amount`) para no re-parsear texto.
     */
    public function up(): void
    {
        Schema::table('quote_alternatives', function (Blueprint $table) {
            $table->decimal('sum_asegurada', 14, 2)->nullable()->after('precio');
        });
    }

    public function down(): void
    {
        Schema::table('quote_alternatives', function (Blueprint $table) {
            $table->dropColumn('sum_asegurada');
        });
    }
};
