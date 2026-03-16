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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();       // 'pas.opportunity_timeout_minutes'
            $table->string('group', 50);            // 'pas', 'checkout', 'mobile_app', 'poliza_api'
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string | integer | boolean | secret
            $table->string('label');                // "Tiempo de aceptación PAS (minutos)"
            $table->text('description')->nullable();
            $table->boolean('is_secret')->default(false); // oculta el valor en la UI
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
