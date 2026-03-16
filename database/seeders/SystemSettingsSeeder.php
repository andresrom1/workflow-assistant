<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ─── Grupo: PAS (oportunidades móviles) ───────────────────────
            [
                'key'         => 'pas.opportunity_timeout_minutes',
                'group'       => 'pas',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Tiempo de aceptación (minutos)',
                'description' => 'Cuántos minutos tiene un PAS para aceptar una oportunidad antes del fallback automático a API.',
                'is_secret'   => false,
            ],
            [
                'key'         => 'pas.http_timeout_seconds',
                'group'       => 'pas',
                'value'       => '10',
                'type'        => 'integer',
                'label'       => 'Timeout HTTP al enviar oportunidad (segundos)',
                'description' => 'Tiempo máximo de espera al llamar al endpoint de la app móvil del PAS.',
                'is_secret'   => false,
            ],

            // ─── Grupo: Mobile App ─────────────────────────────────────────
            [
                'key'         => 'mobile_app.endpoint',
                'group'       => 'mobile_app',
                'value'       => env('MOBILE_APP_OPPORTUNITIES_ENDPOINT', ''),
                'type'        => 'string',
                'label'       => 'URL del endpoint de oportunidades',
                'description' => 'Endpoint al que se envían las nuevas oportunidades de cotización para los PAS.',
                'is_secret'   => false,
            ],
            [
                'key'         => 'mobile_app.webhook_secret',
                'group'       => 'mobile_app',
                'value'       => '',
                'type'        => 'secret',
                'label'       => 'Webhook Secret',
                'description' => 'Clave para validar la autenticidad de los webhooks entrantes desde la app.',
                'is_secret'   => true,
            ],

            // ─── Grupo: Checkout ───────────────────────────────────────────
            [
                'key'         => 'checkout.required_photos',
                'group'       => 'checkout',
                'value'       => '7',
                'type'        => 'integer',
                'label'       => 'Fotos requeridas en inspección',
                'description' => 'Cantidad mínima de fotos que debe subir el cliente para completar el checkout.',
                'is_secret'   => false,
            ],
            [
                'key'         => 'checkout.temp_photo_ttl_hours',
                'group'       => 'checkout',
                'value'       => '24',
                'type'        => 'integer',
                'label'       => 'TTL de fotos temporales (horas)',
                'description' => 'Tiempo de vida de fotos en estado "temp" antes de ser eliminadas por el cleanup job.',
                'is_secret'   => false,
            ],
            [
                'key'         => 'checkout.notifications_email',
                'group'       => 'checkout',
                'value'       => env('CHECKOUT_NOTIFICATIONS_TO', ''),
                'type'        => 'string',
                'label'       => 'Email de notificaciones de checkout',
                'description' => 'Destinatario del mail interno cuando un cliente completa el checkout.',
                'is_secret'   => false,
            ],

            // ─── Grupo: Póliza API ─────────────────────────────────────────
            [
                'key'         => 'poliza_api.base_url',
                'group'       => 'poliza_api',
                'value'       => env('POLIZA_API_BASE_URL', ''),
                'type'        => 'string',
                'label'       => 'URL base de la API de emisión',
                'description' => 'Endpoint raíz de la API de la aseguradora para emisión de pólizas.',
                'is_secret'   => false,
            ],
            [
                'key'         => 'poliza_api.key',
                'group'       => 'poliza_api',
                'value'       => env('POLIZA_API_KEY', ''),
                'type'        => 'secret',
                'label'       => 'API Key de emisión',
                'description' => 'Credencial de autenticación para la API de emisión.',
                'is_secret'   => true,
            ],
            [
                'key'         => 'poliza_api.timeout_seconds',
                'group'       => 'poliza_api',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Timeout de la API (segundos)',
                'description' => 'Tiempo máximo de espera para respuesta de la API de emisión.',
                'is_secret'   => false,
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