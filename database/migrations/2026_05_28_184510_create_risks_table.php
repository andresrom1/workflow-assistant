<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bien asegurado (auto, futuras: moto/hogar/AP/vida).
     *
     * STI + JSONB: una sola tabla con `type` enum y `metadata` JSONB para
     * atributos type-specific. Para type=vehicle: { patente, marca, modelo,
     * version, year, combustible, uso, codigo_postal }.
     *
     * No confundir con `vehicles` (snapshot de cotización del chat). Ese
     * modelo queda intocado y vive en su propio dominio.
     *
     * Un Risk pertenece a un Customer titular y puede tener varios shared_risks
     * (conductores adicionales por email).
     */
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type'); // RiskType enum (vehicle, ...)
            $table->string('label'); // display ej "Toyota Corolla 2021"
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
