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
        // Store del token opaco del catálogo del proveedor (p.ej. el version_id
        // de Visred) por snapshot. GENÉRICA: columna `provider`, cero nombres de
        // proveedor en el dominio. Guarda la decisión de resolución por cotización
        // — el adapter de cotización lee `external_vehicle_ref` ya resuelto en
        // quote-time. Ver docs/v2/10 §8 y visred-integration/RESOLVER-DESIGN.md §4/§7.
        Schema::create('risk_provider_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_vehicle_ref');
            $table->timestamps();

            // Una decisión por (snapshot, proveedor).
            $table->unique(['risk_snapshot_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_provider_refs');
    }
};
