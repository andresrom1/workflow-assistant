<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra el grupo de settings `followup` (seguimiento de conversaciones
     * estancadas). `SettingsService::saveGroup()` sólo actualiza keys que ya
     * existen — por eso se siembran acá.
     */
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'followup.enabled'],
            [
                'group' => 'followup',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Seguimiento de conversaciones estancadas',
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'followup')->delete();
    }
};
