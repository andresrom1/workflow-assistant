<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra el grupo de settings `facturacion` con los datos del emisor (editable desde
     * la pantalla de Configuración de facturación). Los valores iniciales de CUIT / punto de
     * venta salen del `.env` (`AFIP_*`); el resto arranca vacío o con un default sensato.
     *
     * `SettingsService::saveGroup()` sólo actualiza keys que ya existen — por eso se siembran acá.
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'facturacion.razon_social', 'value' => null, 'type' => 'string', 'label' => 'Razón social'],
            ['key' => 'facturacion.cuit', 'value' => config('afip.cuit'), 'type' => 'string', 'label' => 'CUIT del emisor'],
            ['key' => 'facturacion.punto_venta', 'value' => (string) config('afip.punto_venta'), 'type' => 'integer', 'label' => 'Punto de venta'],
            ['key' => 'facturacion.condicion_iva', 'value' => 'Responsable Monotributo', 'type' => 'string', 'label' => 'Condición frente al IVA'],
            ['key' => 'facturacion.subtitulo', 'value' => 'Productor Asesor de Seguros', 'type' => 'string', 'label' => 'Subtítulo'],
            ['key' => 'facturacion.domicilio', 'value' => null, 'type' => 'string', 'label' => 'Domicilio comercial'],
            ['key' => 'facturacion.ingresos_brutos', 'value' => null, 'type' => 'string', 'label' => 'Ingresos Brutos'],
            ['key' => 'facturacion.inicio_actividades', 'value' => null, 'type' => 'string', 'label' => 'Inicio de actividades'],
        ];

        foreach ($rows as $row) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'group' => 'facturacion',
                    'value' => $row['value'],
                    'type' => $row['type'],
                    'label' => $row['label'],
                    'is_secret' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'facturacion')->delete();
    }
};
