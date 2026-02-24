<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            // Reemplazar domicilio por 5 campos individuales
            $table->string('domicilio_calle')->nullable()->after('domicilio');
            $table->string('domicilio_numero', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_cp', 10)->nullable()->after('domicilio_numero');
            $table->string('domicilio_provincia')->nullable()->after('domicilio_cp');
            $table->string('domicilio_localidad')->nullable()->after('domicilio_provincia');

            // Datos del vehículo ingresados por el cliente
            $table->string('vehiculo_uso')->nullable()->after('telefono'); // particular / otro
            $table->string('vehiculo_nro_chasis')->nullable()->after('vehiculo_uso');
            $table->string('vehiculo_nro_motor')->nullable()->after('vehiculo_nro_chasis');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'domicilio_calle',
                'domicilio_numero',
                'domicilio_cp',
                'domicilio_provincia',
                'domicilio_localidad',
                'vehiculo_uso',
                'vehiculo_nro_chasis',
                'vehiculo_nro_motor',
            ]);
        });
    }
};
