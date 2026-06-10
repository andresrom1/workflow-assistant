<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos del titular (`person_holder`) y GNC que la emisión Visred exige y el
     * checkout no capturaba (deuda D1/D2). `nombre`/`telefono` se mantienen (poblados
     * desde los splits) para no romper mail/admin que los leen; los splits nuevos
     * (`first_name`/`last_name`, `phone_prefix`/`phone_number`) son los que consume
     * el holder neutro. Agnósticos: el adapter Visred los mapea, el dominio no.
     */
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('nombre');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('birthdate')->nullable()->after('last_name');
            $table->string('sex_id')->nullable()->after('birthdate');
            $table->string('tax_condition_id')->nullable()->after('sex_id');
            $table->string('phone_prefix', 3)->nullable()->after('telefono');
            $table->string('phone_number', 9)->nullable()->after('phone_prefix');
            $table->boolean('has_gnc')->default(false)->after('vehiculo_nro_motor');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name', 'last_name', 'birthdate', 'sex_id',
                'tax_condition_id', 'phone_prefix', 'phone_number', 'has_gnc',
            ]);
        });
    }
};
