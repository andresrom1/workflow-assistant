<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PAS asignado al cliente (asesor dedicado, spec v2 §1).
     *
     * Nullable: clientes históricos sin PAS asignado existen y no se rompen.
     * FK suave a users (no enforced) porque PAS son User con role=pas y no
     * queremos cascade delete: si se borra un PAS, el customer queda
     * desasignado en vez de desaparecer.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('pas_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['pas_id']);
            $table->dropColumn('pas_id');
        });
    }
};
