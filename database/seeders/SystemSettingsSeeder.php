<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ─── Grupo: Checkout ───────────────────────────────────────────
            [
                'key' => 'checkout.required_photos',
                'group' => 'checkout',
                'value' => '7',
                'type' => 'integer',
                'label' => 'Fotos requeridas en inspección',
                'description' => 'Cantidad mínima de fotos que debe subir el cliente para completar el checkout.',
                'is_secret' => false,
            ],
            [
                'key' => 'checkout.temp_photo_ttl_hours',
                'group' => 'checkout',
                'value' => '24',
                'type' => 'integer',
                'label' => 'TTL de fotos temporales (horas)',
                'description' => 'Tiempo de vida de fotos en estado "temp" antes de ser eliminadas por el cleanup job.',
                'is_secret' => false,
            ],
            [
                'key' => 'checkout.notifications_email',
                'group' => 'checkout',
                'value' => env('CHECKOUT_NOTIFICATIONS_TO', ''),
                'type' => 'string',
                'label' => 'Email de notificaciones de checkout',
                'description' => 'Destinatario del mail interno cuando un cliente completa el checkout.',
                'is_secret' => false,
            ],

            // ─── Grupo: Póliza API ─────────────────────────────────────────
            [
                'key' => 'poliza_api.base_url',
                'group' => 'poliza_api',
                'value' => env('POLIZA_API_BASE_URL', ''),
                'type' => 'string',
                'label' => 'URL base de la API de emisión',
                'description' => 'Endpoint raíz de la API de la aseguradora para emisión de pólizas.',
                'is_secret' => false,
            ],
            [
                'key' => 'poliza_api.key',
                'group' => 'poliza_api',
                'value' => env('POLIZA_API_KEY', ''),
                'type' => 'secret',
                'label' => 'API Key de emisión',
                'description' => 'Credencial de autenticación para la API de emisión.',
                'is_secret' => true,
            ],
            [
                'key' => 'poliza_api.timeout_seconds',
                'group' => 'poliza_api',
                'value' => '30',
                'type' => 'integer',
                'label' => 'Timeout de la API (segundos)',
                'description' => 'Tiempo máximo de espera para respuesta de la API de emisión.',
                'is_secret' => false,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
