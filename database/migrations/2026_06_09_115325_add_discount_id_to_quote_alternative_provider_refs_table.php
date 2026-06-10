<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `discount_id` (bonificación elegida por la `DiscountPolicy`) por alternativa.
     *
     * Como `cotizar/` no acepta descuento, el adapter aplica el % al fee y guarda
     * acá el `value` del descuento elegido para mandarlo en `PreSaleVehicleRequest.
     * discount_id` al emitir (precio cotizado == cobrado). Token del proveedor,
     * aislado del dominio (ADR-001).
     */
    public function up(): void
    {
        Schema::table('quote_alternative_provider_refs', function (Blueprint $table): void {
            $table->string('discount_id')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('quote_alternative_provider_refs', function (Blueprint $table): void {
            $table->dropColumn('discount_id');
        });
    }
};
