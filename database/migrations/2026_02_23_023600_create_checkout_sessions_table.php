<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quote_id')
                ->constrained()
                ->onDelete('cascade');

            $table->foreignId('quote_alternative_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('status')->default('pending'); // pending, submitted, expired, processed

            // Datos personales del tomador
            $table->string('nombre')->nullable();
            $table->string('dni')->nullable();
            $table->string('domicilio')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();

            // Tarjeta de crédito
            // cc_brand no se cifra — es un enum sin valor sensible
            $table->string('cc_brand')->nullable();
            // Campos cifrados con Crypt::encrypt() (AES-256-CBC)
            $table->text('cc_pan_encrypted')->nullable();
            $table->text('cc_expiry_encrypted')->nullable();
            $table->text('cc_holder_name_encrypted')->nullable();
            $table->text('cc_holder_dni_encrypted')->nullable();
            // Sin CVV — no se solicita ni almacena

            // Ciclo de vida del procesamiento manual por auditor
            $table->timestamp('cc_processed_at')->nullable();
            $table->foreignId('cc_processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cc_cleared_at')->nullable();

            // Fotos de inspección (array de Cloudinary public IDs)
            $table->json('photo_paths')->nullable();

            // Auditoría
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
