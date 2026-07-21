<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Elimina el grupo de settings `poliza_api` — residuo del proyecto legacy
     * pas-mobile (su "API de Emisión" fue reemplazada por Visred en la cirugía
     * v2). El bloque `services.poliza_api` no tiene ningún consumidor en app/
     * y la pantalla de Configuración mostraba un dashboard muerto con estos
     * valores.
     */
    public function up(): void
    {
        DB::table('system_settings')->where('group', 'poliza_api')->delete();
    }

    public function down(): void
    {
        $rows = [
            ['key' => 'poliza_api.base_url', 'value' => '', 'type' => 'string', 'label' => 'URL base de la API de emisión', 'is_secret' => false],
            ['key' => 'poliza_api.key', 'value' => '', 'type' => 'secret', 'label' => 'API Key de emisión', 'is_secret' => true],
            ['key' => 'poliza_api.timeout_seconds', 'value' => '30', 'type' => 'integer', 'label' => 'Timeout de la API (segundos)', 'is_secret' => false],
        ];

        foreach ($rows as $row) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'group' => 'poliza_api',
                    'value' => $row['value'],
                    'type' => $row['type'],
                    'label' => $row['label'],
                    'is_secret' => $row['is_secret'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
};
