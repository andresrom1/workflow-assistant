<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cola de staging del ingestor local de documentos de póliza (doc v3/04).
     *
     * El ingestor sube JSON (contrato §2) + PDF; acá se estaciona la metadata cruda
     * (`payload`) + el PDF (en R2) hasta confirmación humana. Recién al confirmar se
     * materializa `Customer→Risk→Poliza→PolicyDocument` y se setean `poliza_id` /
     * `policy_document_id`. Las columnas denormalizadas son para dedup/listado/agrupación;
     * la fuente de verdad del contrato es `payload`.
     *
     * Idempotencia: `hash_sha256` único — un reenvío del mismo PDF no duplica.
     */
    public function up(): void
    {
        Schema::create('ingested_documents', function (Blueprint $table): void {
            $table->id();

            // Dedup: clave de idempotencia (sha256 del PDF original).
            $table->string('hash_sha256')->unique();

            // Denormalizado para listar/agrupar sin abrir el payload. Agrupación de
            // documentos del mismo contrato por `numero_poliza`, fallback por `patente`.
            $table->string('kind');
            $table->string('compania')->nullable();
            $table->string('numero_poliza')->nullable();
            $table->string('documento_numero')->nullable();
            $table->string('patente')->nullable();

            $table->string('status')->default('pendiente'); // pendiente | confirmado | descartado

            // Archivo en R2 + identidad original.
            $table->string('original_filename')->nullable();
            $table->string('storage_path');
            $table->string('storage_url')->nullable();
            $table->timestamp('detectado_en')->nullable();

            // Contrato completo (fidelidad / re-materialización) + qué no se pudo extraer.
            $table->jsonb('payload');
            $table->jsonb('campos_no_extraidos')->nullable();

            // Se setean al confirmar (materialización).
            $table->foreignId('poliza_id')->nullable()->constrained('polizas')->nullOnDelete();
            $table->foreignId('policy_document_id')->nullable()->constrained('policy_documents')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('numero_poliza');
            $table->index('patente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_documents');
    }
};
