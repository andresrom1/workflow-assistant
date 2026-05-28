<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token de tracking en tiempo real para Estado 2 de "Necesito Ayuda".
     *
     * Solo se crea cuando el usuario activa el Estado 2 (necesito que vengas).
     * La URL pública `https://mango.broker/track/{token}` muestra la última
     * posición conocida; el dispositivo del usuario hace POST cada 2 min.
     *
     * Spec v2 §4.3: máx 4hs de duración, dedup por estado del cliente
     * (lock + slider), revocable en cualquier momento (Estado 4).
     *
     * `estado_inicial` opcional para discriminar 1 vs 2 si en el futuro
     * decidimos crear el row también para Estado 1 (hoy no).
     */
    public function up(): void
    {
        Schema::create('emergency_tracking_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_account_id')
                ->constrained('mobile_accounts')
                ->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->decimal('last_lat', 9, 6)->nullable();
            $table->decimal('last_lon', 9, 6)->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['mobile_account_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_tracking_tokens');
    }
};
