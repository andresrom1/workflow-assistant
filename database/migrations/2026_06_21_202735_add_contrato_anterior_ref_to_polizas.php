<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Back-ref de renovación (docs/v3): cuando una póliza renueva, la NUEVA apunta a
     * la anterior por su `numero`. El forward ("se renueva en X") se deriva por reverse
     * lookup; no se almacena (evita doble fuente de verdad). Cross-compañía la cadena
     * se ancla en el Risk; el back-ref une las de la misma compañía.
     */
    public function up(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->string('contrato_anterior_ref')->nullable()->after('numero');
            $table->index('contrato_anterior_ref');
        });
    }

    public function down(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->dropIndex(['contrato_anterior_ref']);
            $table->dropColumn('contrato_anterior_ref');
        });
    }
};
