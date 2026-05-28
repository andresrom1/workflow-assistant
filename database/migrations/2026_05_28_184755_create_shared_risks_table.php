<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuenta Compartida (`shared_risk` en código, "Cuenta Compartida" en UI).
     *
     * Una invitación de un titular a otro usuario para que vea/use un Risk
     * (auto) como conductor adicional. Spec v2 §4.6.
     *
     * La key del match es el EMAIL: el invitado debe loguearse con OAuth
     * usando el mismo email al que se envió el link. Pattern C — el invitado
     * puede no tener Customer propio.
     *
     * Estados derivados (no en DB):
     *   - pendiente: !accepted_at && !revoked_at && expires_at > now
     *   - aceptado: accepted_at && !revoked_at
     *   - revocado: revoked_at
     *   - expirado: !accepted_at && expires_at <= now
     *
     * Límite "máx 2 conductores adicionales por risk" vive en código.
     */
    public function up(): void
    {
        Schema::create('shared_risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->string('shared_with_email');
            $table->foreignId('invited_by_mobile_account_id')
                ->constrained('mobile_accounts')
                ->cascadeOnDelete();
            $table->foreignId('accepted_by_mobile_account_id')
                ->nullable()
                ->constrained('mobile_accounts')
                ->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['risk_id', 'revoked_at']);
            $table->index(['shared_with_email', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_risks');
    }
};
