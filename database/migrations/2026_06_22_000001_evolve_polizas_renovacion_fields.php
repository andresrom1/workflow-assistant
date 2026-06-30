<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evoluciona el vínculo de renovación y agrega las reglas de renovabilidad del
 * centro de mantenimiento de cartera.
 *
 * - `contrato_anterior_ref` (string) → `contrato_anterior_id` (FK self): la sucesora
 *   apunta a la anterior por su id (clearing por sucesora, agnóstico del origen —
 *   renovación o cambio de compañía). Migración limpia: 0 filas con back-ref hoy.
 * - `periodo_corto`: marca la excepción que no se renueva (p. ej. AP por días).
 * - `no_renovar_at`: descarte honesto sin anular (la póliza queda vencida pero sale de la cola).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->dropIndex(['contrato_anterior_ref']);
            $table->dropColumn('contrato_anterior_ref');

            $table->unsignedBigInteger('contrato_anterior_id')->nullable()->after('numero');
            $table->foreign('contrato_anterior_id')
                ->references('id')->on('polizas')
                ->nullOnDelete();
            $table->index('contrato_anterior_id');

            $table->boolean('periodo_corto')->default(false)->after('contrato_anterior_id');
            $table->timestamp('no_renovar_at')->nullable()->after('periodo_corto');
        });
    }

    public function down(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->dropForeign(['contrato_anterior_id']);
            $table->dropIndex(['contrato_anterior_id']);
            $table->dropColumn(['contrato_anterior_id', 'periodo_corto', 'no_renovar_at']);

            $table->string('contrato_anterior_ref')->nullable()->after('numero');
            $table->index('contrato_anterior_ref');
        });
    }
};
