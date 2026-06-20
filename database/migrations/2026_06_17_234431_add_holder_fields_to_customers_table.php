<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alinea el `Customer` con el `person_holder` que exige la emisión Visred y suma el
     * domicilio del tomador. El `Customer` pasa a ser el registro canónico consolidado
     * (chat + checkout sincronizan de vuelta). `name` se mantiene (poblado desde los
     * splits) para compat con Avatar/búsqueda/mail; el domicilio del riesgo NO vive acá
     * (es el `codigo_postal` de guarda en `vehicles`). Ver docs/v2/11.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('birthdate')->nullable()->after('last_name');
            $table->string('sex_id')->nullable()->after('birthdate');
            $table->string('tax_condition_id')->nullable()->after('sex_id');
            $table->string('document_type_id')->default('dni')->after('dni');
            $table->string('person_type_id')->default('fisica')->after('document_type_id');
            $table->string('domicilio_calle')->nullable()->after('tax_condition_id');
            $table->string('domicilio_numero', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_cp', 10)->nullable()->after('domicilio_numero');
            $table->string('domicilio_provincia', 100)->nullable()->after('domicilio_cp');
            $table->string('domicilio_localidad', 100)->nullable()->after('domicilio_provincia');
        });

        // Backfill best-effort: parte `name` en first/last donde haya un nombre cargado.
        foreach (DB::table('customers')->whereNotNull('name')->where('name', '!=', '')->get(['id', 'name']) as $row) {
            $parts = preg_split('/\s+/', trim((string) $row->name), 2);
            DB::table('customers')->where('id', $row->id)->update([
                'first_name' => $parts[0] ?? null,
                'last_name' => $parts[1] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name', 'last_name', 'birthdate', 'sex_id', 'tax_condition_id',
                'document_type_id', 'person_type_id',
                'domicilio_calle', 'domicilio_numero', 'domicilio_cp',
                'domicilio_provincia', 'domicilio_localidad',
            ]);
        });
    }
};
