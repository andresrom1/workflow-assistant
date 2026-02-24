<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Token opaco (UUID) para URLs limpias de checkout
            $table->string('checkout_token', 64)->nullable()->unique()->after('status');
            // ID de la alternativa seleccionada para este checkout
            $table->unsignedBigInteger('checkout_alternative_id')->nullable()->after('checkout_token');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['checkout_token', 'checkout_alternative_id']);
        });
    }
};
