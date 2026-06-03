<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Elimina el setting legacy del flujo PAS opportunities. El watchdog
     * CheckQuoteAcceptance que consumía este valor fue extirpado junto con
     * los estados offered_pas/rejected_pas.
     */
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'pas.opportunity_timeout_minutes')
            ->delete();
    }

    public function down(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'pas.opportunity_timeout_minutes'],
            [
                'group' => 'pas',
                'value' => '30',
                'type' => 'integer',
                'label' => 'Timeout de resolución (minutos)',
                'description' => 'Minutos antes de que una cotización colgada en pending se resuelva automáticamente (vigilante de abandono).',
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
