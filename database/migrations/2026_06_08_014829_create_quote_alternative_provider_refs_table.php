<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referencia opaca del proveedor POR ALTERNATIVA de cotización.
     *
     * Aísla del dominio (`quote_alternatives`, ADR-001) el token que la emisión
     * necesita: el `quotation_result_id` de Visred de la cobertura elegida en
     * checkout. Espeja la convención `*_provider_refs` (ver `risk_provider_refs`,
     * por snapshot). Distinta de `quote_provider_refs` (auditoría per-quote del
     * raw): acá la grana es la alternativa. Ver docs/v2/10 §3 (`provider_result_ref`).
     */
    public function up(): void
    {
        Schema::create('quote_alternative_provider_refs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_alternative_id')->constrained()->cascadeOnDelete();
            // Token opaco del proveedor: el quotation_result_id que referencia `emitir/`.
            $table->string('external_quote_id')->index();
            // Flag de la cotización: si la cobertura exige inspección antes de emitir
            // (condiciona si el payload de emisión embebe `inspections[]`).
            $table->boolean('requires_inspection_before_emission')->default(false);
            $table->timestamps();

            // Una referencia por alternativa.
            $table->unique('quote_alternative_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_alternative_provider_refs');
    }
};
