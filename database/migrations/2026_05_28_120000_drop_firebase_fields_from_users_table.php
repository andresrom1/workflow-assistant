<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revierte 2026_05_27_000000_add_firebase_fields_to_users_table.
 *
 * La identidad de Firebase se movió a la tabla `mobile_accounts` (modelo
 * MobileAccount). `users` vuelve a ser exclusivo del admin panel (Breeze),
 * sin tokens Sanctum ni acoplamiento con Customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['firebase_uid', 'avatar_url']);
        });

        // Restauramos el password como NOT NULL: los users de admin siempre
        // tienen password (Breeze los crea con uno).
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid')->nullable()->unique()->after('id');
            $table->string('avatar_url')->nullable()->after('email');
            $table->foreignId('customer_id')
                ->nullable()
                ->after('avatar_url')
                ->constrained('customers')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }
};
