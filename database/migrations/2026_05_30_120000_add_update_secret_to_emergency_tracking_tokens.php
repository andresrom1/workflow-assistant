<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secreto de escritura del tracking (spec v2 §4.3, decisión de seguridad C).
     *
     * El `token` viaja en la URL pública `mango.broker/track/{token}` y se
     * comparte con los contactos: da SOLO lectura. El `update_secret` nunca
     * sale del dispositivo del asegurado y es la única llave para ESCRIBIR
     * la posición vía PATCH. Así, si el link de WhatsApp se filtra, nadie
     * puede postear ubicaciones falsas: solo el device que generó el tracking.
     *
     * Nullable: los tokens viejos (creados antes de esta migración) no lo
     * tienen; un PATCH contra ellos falla con 404 neutro (no se pueden revivir).
     */
    public function up(): void
    {
        Schema::table('emergency_tracking_tokens', function (Blueprint $table): void {
            $table->string('update_secret', 64)->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('emergency_tracking_tokens', function (Blueprint $table): void {
            $table->dropColumn('update_secret');
        });
    }
};
