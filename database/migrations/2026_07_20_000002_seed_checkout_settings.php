<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra el grupo de settings `checkout`. Hasta ahora estas keys vivían
     * sólo en `SystemSettingsSeeder`, que estaba huérfano (nadie lo llamaba):
     * en dev nunca existieron y los consumidores sobrevivían por el default
     * de `SettingsService::get($key, $default)`. Se unifica la vía del repo:
     * seed por migración de feature (como `facturacion` y `followup`).
     *
     * A diferencia de esas migraciones, acá se inserta SÓLO SI LA KEY NO
     * EXISTE: en prod estas filas ya pudieron crearse (cuando el seeder corrió
     * alguna vez) y editarse desde el admin — un `updateOrInsert` pisaría el
     * valor editado con el default del seed.
     *
     * `SettingsService::saveGroup()` sólo actualiza keys que ya existen — por
     * eso se siembran acá.
     */
    public function up(): void
    {
        $rows = [
            [
                'key' => 'checkout.required_photos',
                'value' => '7',
                'type' => 'integer',
                'label' => 'Fotos requeridas en inspección',
                'description' => 'Cantidad mínima de fotos que debe subir el cliente para completar el checkout.',
            ],
            [
                'key' => 'checkout.temp_photo_ttl_hours',
                'value' => '24',
                'type' => 'integer',
                'label' => 'TTL de fotos temporales (horas)',
                'description' => 'Tiempo de vida de fotos en estado "temp" antes de ser eliminadas por el cleanup job.',
            ],
            [
                'key' => 'checkout.notifications_email',
                'value' => config('mail.checkout_notifications_to'),
                'type' => 'string',
                'label' => 'Email de notificaciones de checkout',
                'description' => 'Destinatario del mail interno cuando un cliente completa el checkout o falla una emisión.',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('system_settings')->where('key', $row['key'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert([
                ...$row,
                'group' => 'checkout',
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'checkout')->delete();
    }
};
