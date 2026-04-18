<?php

// Script para crear datos de prueba de checkout usando Tinker
// Ejecutar con: php artisan tinker < checkout_test_data.php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Support\Str;

// 1. Crear entidades base (Customer, Vehicle, Conversation)
$customer = Customer::factory()->create([
    'name' => 'Test Customer Checkout',
    'email' => 'checkout-test@example.com',
    'phone' => '+5491123456789',
]);

$vehicle = Vehicle::factory()->create([
    'customer_id' => $customer->id,
    'marca' => 'Ford',
    'modelo' => 'Focus',
    'year' => 2021,
    'patente' => 'XYZ789',
]);

$conversation = Conversation::factory()->create([
    'customer_id' => $customer->id,
    'status' => 'active',
]);

// Vincular vehículo a conversación
$conversation->vehicles()->attach($vehicle->id, ['is_primary' => true]);

// 2. Crear RiskSnapshot
$riskSnapshot = RiskSnapshot::create([
    'customer_id' => $customer->id,
    'vehicle_id' => $vehicle->id,
    'conversation_id' => $conversation->id,
    'data' => [
        'customer' => $customer->toArray(),
        'vehicle' => $vehicle->toArray(),
        'coverage_preference' => 'todo_riesgo',
    ],
]);

// 3. Crear Quote en estado 'processed' (para que tenga alternativas)
$quote = Quote::create([
    'session_uuid' => Str::uuid(),
    'risk_snapshot_id' => $riskSnapshot->id,
    'conversation_id' => $conversation->id,
    'status' => 'processed',
    'expires_at' => now()->addDays(7),
]);

// 4. Crear alternativas para la quote
$alternatives = [
    [
        'aseguradora' => 'Rivadavia',
        'descripcion' => 'A1 - Todo Riesgo Premium',
        'titulo' => 'A1',
        'normalized_grade' => 'A',
        'precio' => 35000.00,
        'moneda' => 'ARS',
        'marketing_title' => 'Todo Riesgo Premium con Asistencia',
        'sum_insured_text' => 'Suma asegurada: $4.000.000',
        'features_tags' => ['Todo Riesgo', 'Granizo', 'Asistencia 24hs', 'Chofer'],
        'full_details' => [
            'cobertura' => 'Todo Riesgo sin franquicia',
            'deducible' => '0%',
            'beneficios' => ['Asistencia 24/7', 'Chofer reemplazo ilimitado', 'Vehículo 0km de reemplazo'],
        ],
    ],
    [
        'aseguradora' => 'Provincia Seguros',
        'descripcion' => 'B1 - Terceros Completos Plus',
        'titulo' => 'B1',
        'normalized_grade' => 'B',
        'precio' => 18000.00,
        'moneda' => 'ARS',
        'marketing_title' => 'Terceros Completos con Beneficios Extra',
        'sum_insured_text' => 'Suma asegurada: $2.000.000',
        'features_tags' => ['Granizo', 'Ruedas', 'Cristales', 'Asistencia'],
        'full_details' => [
            'cobertura' => 'Terceros Completos con granizo y lunetas',
            'deducible' => '2%',
            'beneficios' => ['Asistencia en ruta', 'Reparación de cristales'],
        ],
    ],
];

foreach ($alternatives as $altData) {
    QuoteAlternative::create(array_merge($altData, ['quote_id' => $quote->id]));
}

// 5. Actualizar la quote para checkout
$selectedAlternative = $quote->alternatives()->where('normalized_grade', 'A')->first()
    ?? $quote->alternatives()->first(); // Preferir alternativa A, sino la primera

$checkoutToken = Str::random(10);

$quote->update([
    'status' => 'checkout_pending',
    'checkout_token' => $checkoutToken,
    'checkout_alternative_id' => $selectedAlternative->id,
]);

// 6. Output de información
$checkoutUrl = route('checkout.show', ['token' => $checkoutToken]);

echo "✅ Datos de prueba para checkout creados exitosamente:\n";
echo "   Customer: {$customer->name} (ID: {$customer->id})\n";
echo "   Vehicle: {$vehicle->marca} {$vehicle->modelo} (ID: {$vehicle->id})\n";
echo "   Quote: #{$quote->id} (Status: {$quote->status})\n";
echo "   Checkout Token: {$checkoutToken}\n";
echo "   Selected Alternative: {$selectedAlternative->titulo} - {$selectedAlternative->aseguradora}\n";
echo "   Checkout URL: {$checkoutUrl}\n";
echo "   ⚠️  Recuerda que este es un entorno de prueba.\n";
