<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nombre del invitado a Cuenta Compartida (Vehículo Compartido en UI).
     *
     * El titular ingresa nombre + email al compartir; el nombre es para que la
     * lista "Personas con acceso" muestre a quién invitó, no solo el email.
     * Nullable: invitaciones viejas (seed/Fase 2) no lo tienen.
     */
    public function up(): void
    {
        Schema::table('shared_risks', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('shared_with_email');
        });
    }

    public function down(): void
    {
        Schema::table('shared_risks', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
