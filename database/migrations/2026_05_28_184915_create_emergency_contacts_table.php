<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contactos de emergencia del usuario de la app.
     *
     * Hasta 3 contactos por MobileAccount (límite en código). Spec v2 §4.3.
     *
     * Se almacenan en backend porque el backend necesita dispatchar los
     * WhatsApp en el momento del evento — no es factible mantenerlos solo
     * en el dispositivo.
     */
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_account_id')
                ->constrained('mobile_accounts')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('phone'); // E.164 ej "+5491156782341"
            $table->timestamps();

            $table->index('mobile_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
