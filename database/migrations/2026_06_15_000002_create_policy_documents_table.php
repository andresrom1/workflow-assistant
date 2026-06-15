<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos oficiales de una póliza, persistidos en R2.
     *
     * Dos fuentes (`source`): `visred_emission` se captura automáticamente dentro
     * del `emit()` (mientras vive el `presale_id`) y se baja a R2; `admin_upload`
     * es la carga manual post-emisión (renovaciones/endosos/correcciones — deuda del
     * admin panel). La compañía es el System of Record; esto es un snapshot al emitir.
     * `visible_to_client` decide qué ve mango-mobile. Mismo patrón storage_path/url
     * que `inspection_photos`.
     */
    public function up(): void
    {
        Schema::create('policy_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poliza_id')->constrained('polizas')->cascadeOnDelete();
            $table->string('kind'); // poliza | endoso | cupon | circulation-card | ...
            $table->string('storage_path');
            $table->string('storage_url')->nullable();
            $table->string('source')->default('visred_emission'); // visred_emission | admin_upload
            $table->boolean('visible_to_client')->default(true);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['poliza_id', 'visible_to_client']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
