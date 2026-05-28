<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad Firebase del usuario de la app móvil.
 *
 * Tabla independiente de `users` (admin panel) y de `customers` (tomador).
 * El vínculo a Customer es 1:1 nullable, se setea cuando el usuario confirma
 * su DNI en el LinkDniScreen.
 *
 * - customer_id null = identidad verificada por OAuth pero todavía sin
 *   reclamar un Customer (Pattern B en el spec) o un tercero que solo accede
 *   a pólizas compartidas vía email (Pattern C, sin Customer propio).
 * - customer_id != null = Customer reclamado, ya puede ver sus pólizas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_accounts', function (Blueprint $table) {
            $table->id();

            // Identidad del proveedor OAuth (Google/Apple vía Firebase).
            $table->string('firebase_uid')->unique();

            // Email verificado por OAuth. Es la llave del usuario en la app
            // (recibe notificaciones, accede a pólizas compartidas por email).
            $table->string('email')->unique();

            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            // Vínculo con el tomador real. Único (1:1) y nullable: una
            // MobileAccount puede no tener Customer (Pattern C) y un Customer
            // puede no tener MobileAccount (nunca instaló la app).
            $table->foreignId('customer_id')
                ->nullable()
                ->unique()
                ->constrained('customers')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_accounts');
    }
};
