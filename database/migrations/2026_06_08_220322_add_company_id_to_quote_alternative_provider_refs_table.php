<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slug opaco de la compañía del proveedor por alternativa.
     *
     * Necesario ANTES de emitir para resolver los tipos de inspección que la
     * compañía exige (inspección before-emisión, deuda D4.1). El adapter de
     * cotización ya lo conoce (`result.company_id`); persistirlo acá lo deja
     * disponible en emit-time sin reconsultar. Aislado del dominio (ADR-001).
     */
    public function up(): void
    {
        Schema::table('quote_alternative_provider_refs', function (Blueprint $table): void {
            $table->string('company_id')->nullable()->after('external_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('quote_alternative_provider_refs', function (Blueprint $table): void {
            $table->dropColumn('company_id');
        });
    }
};
