<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buffer de posiciones del tracking (spec v2 §4.3, mejora de cadencia).
     *
     * El device muestrea cada 10s y sube un batch de 4 (~cada 40s). El backend
     * reproduce el buffer a ritmo real con un offset fijo, de modo que el mapa
     * del contacto se mueva suave cada 10s con un atraso ~constante (≈ offset),
     * en vez de saltar cada 2 min.
     *
     * `effective_at` NO es el timestamp crudo del device (sujeto a clock-skew):
     * al recibir el batch se ancla la muestra más nueva al `now()` del server y
     * las demás se espacian por su delta intra-batch. Así el replay usa solo el
     * timing relativo del device (confiable), no su reloj absoluto.
     */
    public function up(): void
    {
        Schema::create('emergency_track_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_tracking_token_id')
                ->constrained('emergency_tracking_tokens')
                ->cascadeOnDelete();
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->timestamp('effective_at');
            $table->timestamps();

            // Para el replay: "última posición con effective_at <= cutoff".
            $table->index(['emergency_tracking_token_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_track_positions');
    }
};
