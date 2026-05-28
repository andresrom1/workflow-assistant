<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atributos type-specific por rol en JSONB.
     *
     * Para role=pas: { matricula, phone, avatar_url }.
     * Para role=admin/user: vacío hoy, extensible sin migraciones futuras.
     *
     * Consistente con el modelo de Risk (STI + JSONB) decidido en Fase 2 y
     * con el naming existente en `vehicles.metadata`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->jsonb('metadata')->default('{}')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
