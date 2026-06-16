<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los documentos se acumulan al contrato (la carga manual usa `create`, no
     * reemplaza): varios docs del mismo `kind` conviven. `original_filename` se llena
     * solo del upload y `label` es un texto opcional editable para distinguirlos en
     * la lista del admin (p. ej. "Endoso cambio de uso" vs "Endoso GNC").
     */
    public function up(): void
    {
        Schema::table('policy_documents', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('storage_url');
            $table->string('label')->nullable()->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table): void {
            $table->dropColumn(['original_filename', 'label']);
        });
    }
};
