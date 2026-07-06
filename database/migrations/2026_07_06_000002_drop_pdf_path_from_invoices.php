<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El PDF ya no se persiste en disco: se genera al vuelo (dompdf, deterministic a partir de
     * la `Invoice` + config del emisor) cada vez que se descarga (individual o en el ZIP del
     * lote). `pdf_path` queda sin uso.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable();
        });
    }
};
