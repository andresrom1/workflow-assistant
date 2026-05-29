<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedup server-side de Avisos a Corto Plazo (ACP) del SMN.
     *
     * El feed CAP (https://ssl.smn.gob.ar/feeds/avisocorto_GeoRSS.xml)
     * republica los mismos avisos en cada poll mientras estén vigentes.
     * Esta tabla evita reenviar el mismo `cap:identifier` (urn:oid:...)
     * al topic FCM `acp-ar`.
     *
     * Spec v2 §4.4. Fase 4 backend.
     *
     * `id` es el `cap:identifier` del XML. Es la PK natural: el SMN
     * garantiza unicidad del urn:oid por aviso.
     *
     * `expires_at` se persiste para cleanup. El comando `smn:poll-acp`
     * borra rows con expires_at < now() - 7 días en cada corrida.
     */
    public function up(): void
    {
        Schema::create('acp_procesados', function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->timestamp('expires_at')->index();
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acp_procesados');
    }
};
