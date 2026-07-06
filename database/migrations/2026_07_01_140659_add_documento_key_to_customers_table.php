<?php

use App\Services\CustomerMergeService;
use App\Support\DocumentoIdentidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clave de identidad canónica del cliente (DNI para físicas, CUIT para jurídicas). Colapsa
 * DNI ↔ CUIL/CUIT de la misma persona para lookup y dedup ({@see DocumentoIdentidad}). Índice
 * NO único a propósito: hoy pueden convivir filas del mismo `documento_key` (la fusión la hace
 * {@see CustomerMergeService} en el próximo alta que converja), sin romper la
 * migración. Backfill: normaliza `dni` a solo-dígitos in-place y calcula la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('documento_key')->nullable()->after('dni')->index();
        });

        foreach (DB::table('customers')->whereNotNull('dni')->get(['id', 'dni', 'document_type_id', 'person_type_id']) as $row) {
            $dni = DocumentoIdentidad::normalizar($row->dni);
            DB::table('customers')->where('id', $row->id)->update([
                'dni' => $dni ?? $row->dni,
                'documento_key' => DocumentoIdentidad::clave($dni, $row->document_type_id, $row->person_type_id),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['documento_key']);
            $table->dropColumn('documento_key');
        });
    }
};
