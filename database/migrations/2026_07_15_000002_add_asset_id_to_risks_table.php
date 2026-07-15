<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa InsurableAsset de Risk (ver docs/v3/05-modelo-insurable-asset.md).
 * Backfill 1:1: cada Risk existente obtiene un Asset con la misma metadata
 * (incluye soft-deleted — DB::table no aplica el scope). La normalización de
 * la clave debe ser idéntica a AssetType::naturalKey (solo A-Z0-9, mayúsculas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->constrained('insurable_assets')->cascadeOnDelete();
        });

        DB::table('risks')->orderBy('id')->chunkById(200, function ($risks) {
            foreach ($risks as $risk) {
                $metadata = json_decode($risk->metadata ?? '{}', true) ?: [];
                $patente = strtoupper(trim((string) ($metadata['patente'] ?? '')));
                $naturalKey = (string) preg_replace('/[^A-Z0-9]/', '', $patente);

                $assetId = DB::table('insurable_assets')->insertGetId([
                    'customer_id' => $risk->customer_id,
                    'type' => $risk->type,
                    'label' => $risk->label,
                    'natural_key' => $naturalKey !== '' ? $naturalKey : null,
                    'metadata' => $risk->metadata ?? '{}',
                    'created_at' => $risk->created_at,
                    'updated_at' => now(),
                    'deleted_at' => $risk->deleted_at,
                ]);

                DB::table('risks')->where('id', $risk->id)->update(['asset_id' => $assetId]);
            }
        });

        DB::statement('ALTER TABLE risks ALTER COLUMN asset_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
