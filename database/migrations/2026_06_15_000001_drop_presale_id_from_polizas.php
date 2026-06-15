<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saca `presale_id` de la referencia de póliza durable.
     *
     * `presale_id` es un identificador de Visred que SOLO vive durante el ciclo de
     * emisión (emitir → polling → inspección/documento post-emisión). Persistirlo en
     * `polizas` (referencia durable que lee mango-mobile) lo embebía fuera de ese
     * ciclo. La referencia durable queda `numero` (policy_number) + `company_id`;
     * `presale_id` no sale del adapter de emisión. Revierte la columna agregada en
     * `2026_06_09_001617_repurpose_polizas_as_policy_reference.php`.
     */
    public function up(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->dropIndex(['presale_id']);
            $table->dropColumn('presale_id');
        });
    }

    public function down(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->string('presale_id')->nullable()->after('numero');
            $table->index('presale_id');
        });
    }
};
