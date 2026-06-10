<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repurpose de `polizas` a tabla de REFERENCIA (doc 10 §5b, decisión b).
     *
     * La compañía (vía Visred) es el System of Record de la póliza/estado/endosos
     * (on-demand, última versión). MANGO guarda solo la referencia mínima para
     * consultarla. Por eso se relajan a nullable las columnas de cartera
     * autocontenida (estado/company/coverage/sum_asegurada/vigencia): la referencia
     * las deja vacías y el endpoint de cartera las compone on-demand desde el cache.
     * mango-mobile sigue leyendo `polizas` (modelo compartido — migración coordinada).
     *
     * Se agrega `quote_id` para ligar la referencia al acto comercial (Risk + Quote,
     * doc 10 §5) y los identificadores opacos del proveedor (`presale_id`/`company_id`/
     * `product_id`) + `last_synced_at` para el cache-aside.
     */
    public function up(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->string('estado')->nullable()->change();
            $table->string('company')->nullable()->change();
            $table->string('coverage')->nullable()->change();
            $table->decimal('sum_asegurada', 14, 2)->nullable()->change();
            $table->date('vigencia')->nullable()->change();

            $table->foreignId('quote_id')->nullable()->after('risk_id')->constrained('quotes')->nullOnDelete();
            $table->string('presale_id')->nullable()->after('numero');
            $table->string('company_id')->nullable()->after('company');
            $table->string('product_id')->nullable()->after('company_id');
            $table->timestamp('last_synced_at')->nullable()->after('emitida_en');

            $table->index('presale_id');
        });
    }

    public function down(): void
    {
        Schema::table('polizas', function (Blueprint $table): void {
            $table->dropIndex(['presale_id']);
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn(['presale_id', 'company_id', 'product_id', 'last_synced_at']);

            $table->string('estado')->nullable(false)->change();
            $table->string('company')->nullable(false)->change();
            $table->string('coverage')->nullable(false)->change();
            $table->decimal('sum_asegurada', 14, 2)->nullable(false)->change();
            $table->date('vigencia')->nullable(false)->change();
        });
    }
};
