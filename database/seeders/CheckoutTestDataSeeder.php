<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CheckoutTestDataSeeder extends Seeder
{
    /**
     * Crea datos de prueba para generar un link de checkout válido:
     * 1. Una quote con status 'checkout_pending'
     * 2. Un checkout_token único
     * 3. Una checkout_alternative_id que apunte a una alternative válida
     * 4. La alternative debe existir y estar asociada a la quote
     */
    public function run(): void
    {
        // Generar datos únicos usando timestamp para evitar constranints UNIQUE
        $timestamp = now()->timestamp;
        $uniqueEmail = "test.checkout.{$timestamp}@example.com";
        $uniqueDni = (string) (10000000 + $timestamp % 70000000);
        $uniquePatente = 'TST'.substr($timestamp, -4);

        // 1. Crear o recuperar entidades base (Customer, Vehicle, Conversation)
        $customer = Customer::firstOrCreate(
            ['email' => $uniqueEmail],
            [
                'name' => 'Test Customer Checkout',
                'dni' => $uniqueDni,
                'phone' => '+5491123456789',
                'is_anonymous' => false,
                'metadata' => ['source' => 'checkout_seeder'],
            ]
        );

        $vehicle = Vehicle::firstOrCreate(
            ['patente' => $uniquePatente],
            [
                'customer_id' => $customer->id,
                'marca' => 'Toyota',
                'modelo' => 'Corolla',
                'version' => '2.0 SE',
                'year' => 2020,
                'combustible' => 'nafta',
                'codigo_postal' => '5000',
                'uso' => 'particular',
                'motor' => '1NZFE123456',
                'chasis' => '1HGCM82633A123456',
                'is_complete' => true,
            ]
        );

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'external_conversation_id' => Str::uuid()->toString(),
            'channel' => 'web',
        ]);

        // Vincular vehículo a conversación
        $conversation->vehicles()->attach($vehicle->id, ['is_primary' => true]);

        // 2. Crear RiskSnapshot con todos los campos requeridos
        $riskSnapshot = RiskSnapshot::create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
            'marca' => $vehicle->marca,
            'modelo' => $vehicle->modelo,
            'version' => $vehicle->version,
            'year' => $vehicle->year,
            'combustible' => $vehicle->combustible,
            'uso' => $vehicle->uso,
            'codigo_postal' => $vehicle->codigo_postal,
            'dni' => $customer->dni,
            'edad_conductor' => now()->subYears(35)->toDateString(),
        ]);

        // 3. Crear Quote en estado 'processed' (para que tenga alternativas)
        $quote = Quote::create([
            'session_uuid' => Str::uuid(),
            'risk_snapshot_id' => $riskSnapshot->id,
            'conversation_id' => $conversation->id,
            'status' => 'processed',
            'external_ref_id' => 'test_'.Str::uuid(),
            'expires_at' => now()->addDays(7),
        ]);

        // 4. Crear alternativas para la quote (con los campos disponibles en la tabla actual)
        $alternatives = [
            [
                'aseguradora' => 'Sancor',
                'descripcion' => 'C1 - Terceros Completos',
                'titulo' => 'C1',
                'normalized_grade' => 'third_party_complete',
                'precio' => 15000.00,
                'moneda' => 'ARS',
                'marketing_title' => 'Cobertura Completa Premium',
                'sum_insured_text' => 'Suma asegurada: $2.500.000',
                'features_tags' => ['Granizo', 'Ruedas', 'Cristales'],
                'full_details' => [
                    'cobertura' => 'Terceros Completos con granizo',
                    'deducible' => '0%',
                    'beneficios' => ['Asistencia 24hs', 'Chofer reemplazo'],
                ],
            ],
            [
                'aseguradora' => 'La Caja',
                'descripcion' => 'B2 - Todo Riesgo',
                'titulo' => 'B2',
                'normalized_grade' => 'all_risk',
                'precio' => 25000.00,
                'moneda' => 'ARS',
                'marketing_title' => 'Todo Riesgo con Beneficios',
                'sum_insured_text' => 'Suma asegurada: $3.000.000',
                'features_tags' => ['Todo Riesgo', 'Granizo', 'Robo'],
                'full_details' => [
                    'cobertura' => 'Todo Riesgo con franquicia',
                    'deducible' => '5%',
                    'beneficios' => ['Reparación en taller', 'Vehículo de reemplazo'],
                ],
            ],
        ];

        foreach ($alternatives as $altData) {
            QuoteAlternative::create(array_merge($altData, ['quote_id' => $quote->id]));
        }

        // 5. Actualizar la quote para checkout
        $selectedAlternative = $quote->alternatives()->first();
        $checkoutToken = Str::random(10);

        $quote->update([
            'status' => 'checkout_pending',
            'checkout_token' => $checkoutToken,
            'checkout_alternative_id' => $selectedAlternative->id,
        ]);

        // 6. Output de información
        $checkoutUrl = route('checkout.show', ['token' => $checkoutToken]);

        $this->command->info('');
        $this->command->info('✅ Datos de prueba para checkout creados exitosamente:');
        $this->command->info("   📧 Email: {$uniqueEmail}");
        $this->command->info("   🆔 DNI: {$uniqueDni}");
        $this->command->info("   🚗 Vehículo: {$vehicle->marca} {$vehicle->modelo} {$vehicle->year}");
        $this->command->info("   📍 Patente: {$vehicle->patente}");
        $this->command->info("   💳 Quote: #{$quote->id} (Status: {$quote->status})");
        $this->command->info("   🔑 Checkout Token: {$checkoutToken}");
        $this->command->info("   💰 Alternative: {$selectedAlternative->aseguradora} - {$selectedAlternative->titulo} (\${$selectedAlternative->precio})");
        $this->command->info('');
        $this->command->info('🔗 CHECKOUT URL:');
        $this->command->line("   {$checkoutUrl}");
        $this->command->info('');
        $this->command->warn('   ⚠️  Recuerda que este es un entorno de prueba.');
        $this->command->info('');
    }
}
