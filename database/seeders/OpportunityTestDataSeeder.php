<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simula una oportunidad ficticia completa de punta a punta.
 *
 * Crea todos los registros necesarios en workflow-assistant
 * (Customer, Vehicle, Conversation, RiskSnapshot, Quote) y luego
 * envía el payload al endpoint de pas-web para que se dispare
 * el broadcast a la app Flutter — exactamente igual que el flujo real.
 *
 * Uso:
 *   php artisan db:seed --class=OpportunityTestDataSeeder
 */
class OpportunityTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🌱 Creando oportunidad ficticia de prueba...');

        // ─── 1. Datos ficticios del vehículo ──────────────────────────────────
        $vehicleData = [
            'patente'       => 'AA' . rand(100, 999) . 'BB',
            'marca'         => 'Toyota',
            'modelo'        => 'Corolla',
            'version'       => '2.0 XEI CVT',
            'year'          => 2022,
            'combustible'   => 'nafta',
            'codigo_postal' => '5000',
            'uso'           => 'particular',
        ];

        $coveragePreference = 'Todo Riesgo';

        // ─── 2. Customer ───────────────────────────────────────────────────────
        $customer = Customer::firstOrCreate(
            ['email' => 'test.seeder@pastest.com'],
            [
                'name'  => 'Cliente de Prueba',
                'dni'   => '99999999',
                'phone' => '3511234567',
            ]
        );
        $this->command->line("   👤 Customer ID: {$customer->id} ({$customer->name})");

        // ─── 3. Vehicle ────────────────────────────────────────────────────────
        $vehicle = Vehicle::firstOrCreate(
            ['patente' => $vehicleData['patente']],
            array_merge($vehicleData, ['customer_id' => $customer->id])
        );
        $this->command->line("   🚗 Vehicle ID: {$vehicle->id} ({$vehicle->patente})");

        // ─── 4. Conversation ───────────────────────────────────────────────────
        $threadId = 'seed_thread_' . Str::random(12);
        $conversation = Conversation::create([
            'external_conversation_id' => $threadId,
            'external_user_id'         => 'seed_user_' . Str::random(8),
            'customer_id'              => $customer->id,
            'channel'                  => 'web',
            'status'                   => 'active',
        ]);
        $this->command->line("   💬 Conversation ID: {$conversation->id}");

        // ─── 5. RiskSnapshot ───────────────────────────────────────────────────
        $snapshot = RiskSnapshot::create([
            'vehicle_id'         => $vehicle->id,
            'customer_id'        => $customer->id,
            'marca'              => $vehicleData['marca'],
            'modelo'             => $vehicleData['modelo'],
            'version'            => $vehicleData['version'],
            'year'               => $vehicleData['year'],
            'combustible'        => $vehicleData['combustible'],
            'uso'                => $vehicleData['uso'],
            'codigo_postal'      => $vehicleData['codigo_postal'],
            'dni'                => $customer->dni,
            'coverage_preference' => $coveragePreference,
        ]);
        $this->command->line("   📸 RiskSnapshot ID: {$snapshot->id}");

        // ─── 6. Quote ──────────────────────────────────────────────────────────
        $sessionUuid = Str::uuid()->toString();
        $quote = Quote::create([
            'session_uuid'      => $sessionUuid,
            'risk_snapshot_id'  => $snapshot->id,
            'conversation_id'   => $conversation->id,
            'status'            => 'pending',
        ]);
        $this->command->line("   📋 Quote ID: {$quote->id}");

        // ─── 7. Enviar payload al endpoint de pas-web ──────────────────────────
        $settings = app(SettingsService::class);
        $endpoint    = $settings->get('mobile_app.endpoint', config('services.mobile_app.endpoint'));
        $timeout     = (int) $settings->get('pas.opportunity_timeout_minutes', 30);
        $httpTimeout = (int) $settings->get('pas.http_timeout_seconds', 10);

        if (empty($endpoint)) {
            $this->command->warn('   ⚠️  mobile_app.endpoint no está configurado en system_settings.');
            $this->command->warn('      Registros creados en BD pero el broadcast NO fue enviado.');
            $this->command->warn('      Configurá el endpoint en /admin/settings y reintentá.');
            return;
        }

        $payload = [
            'quote_id'           => $quote->id,
            'snapshot'           => $snapshot->toArray(),
            'coverage_preference' => $coveragePreference,
            'expires_at'         => now()->addMinutes($timeout)->toIso8601String(),
        ];

        $this->command->line("   📡 Enviando a pas-web: {$endpoint}");

        try {
            $response = Http::timeout($httpTimeout)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $opportunityId = $response->json('opportunity_id') ?? ('opp_' . uniqid());

                $quote->update([
                    'status'                  => 'offered_pas',
                    'resolution_method'       => 'mobile',
                    'mobile_opportunity_id'   => $opportunityId,
                    'sent_to_mobile_at'       => now(),
                    'expected_resolution_at'  => now()->addMinutes($timeout),
                ]);

                $quote->mobileSyncLogs()->create([
                    'opportunity_id' => $opportunityId,
                    'status'         => 'success',
                    'response_data'  => $response->json(),
                    'synced_at'      => now(),
                ]);

                $this->command->info("   ✅ Oportunidad enviada. opportunity_id: {$opportunityId}");
                $this->command->info('');
                $this->command->info('   📱 La app Flutter debería recibir la notificación ahora.');
                $this->command->info("   🔗 Quote ID en BD: {$quote->id}  |  Status: offered_pas");

            } else {
                $this->command->error("   ❌ Error HTTP {$response->status()}: {$response->body()}");
                $quote->update(['status' => 'failed']);
            }

        } catch (\Throwable $e) {
            $this->command->error("   ❌ Excepción: {$e->getMessage()}");
            $this->command->warn('      Registros creados en BD pero el broadcast falló.');
            $quote->update(['status' => 'failed']);
            Log::error('[OpportunityTestDataSeeder] Error al enviar a pas-web', [
                'error'    => $e->getMessage(),
                'quote_id' => $quote->id,
            ]);
        }
    }
}
