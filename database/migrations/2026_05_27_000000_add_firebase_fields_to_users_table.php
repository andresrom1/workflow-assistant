<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identidad de Firebase (Google / Apple). Es la clave con la que
            // identificamos al usuario móvil; el resto de los datos vienen de Firebase.
            $table->string('firebase_uid')->nullable()->unique()->after('id');

            // Foto de perfil del proveedor OAuth.
            $table->string('avatar_url')->nullable()->after('email');

            // Vinculación OAuth ↔ tomador (Customer). Null hasta que el usuario
            // declara su DNI y matchea contra un customer existente.
            $table->foreignId('customer_id')
                ->nullable()
                ->after('avatar_url')
                ->constrained('customers')
                ->nullOnDelete();
        });

        // Los usuarios OAuth no tienen password; lo hacemos nullable.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['firebase_uid', 'avatar_url']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
