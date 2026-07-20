<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea los índices únicos de identidad con el global scope de `SoftDeletes`.
 *
 * `Customer` usa SoftDeletes, así que todo lookup de identidad (`findByDni`,
 * `findByEmail`, el guard de clave única de `CustomerConsolidationService`) filtra
 * `deleted_at IS NULL`. Pero los índices únicos eran totales: una fila soft-deleted
 * seguía ocupando el slot del DNI/email sin que Eloquent la viera. Resultado: el
 * checkout no encontraba la fila en conflicto, escribía el DNI igual, y Postgres
 * abortaba la transacción entera con `customers_dni_unique`.
 *
 * Al hacerlos parciales, la restricción pasa a ver exactamente lo mismo que la app:
 * borrar un customer libera su DNI/email, y los lookups actuales quedan correctos
 * por construcción. Ver Bitácora del ROADMAP (2026-07-20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['dni']);
            $table->dropUnique(['email']);
        });

        // Se reusan los nombres originales: al soltar la constraint quedaron libres.
        DB::statement('CREATE UNIQUE INDEX customers_dni_unique ON customers (dni) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX customers_email_unique ON customers (email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_dni_unique');
        DB::statement('DROP INDEX IF EXISTS customers_email_unique');

        // OJO: revertir puede fallar si conviven filas borradas y vivas con el mismo
        // dni/email — precisamente lo que el índice parcial permite.
        Schema::table('customers', function (Blueprint $table): void {
            $table->unique('dni');
            $table->unique('email');
        });
    }
};
