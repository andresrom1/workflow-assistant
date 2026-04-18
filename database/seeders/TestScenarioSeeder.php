<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Services\QuoteService; // ✅ Importamos el servicio
use Illuminate\Database\Seeder;

class TestScenarioSeeder extends Seeder
{
    /**
     * Crea un escenario completo:
     * 1. Entidades Vivas (Customer, Vehicle, Conversation).
     * 2. Vinculación (Pivot).
     * 3. Proceso de Negocio (Cotización Automática).
     * * Laravel inyecta automáticamente el QuoteService aquí.
     */
    public function run(QuoteService $quoteService): void
    {
        // 1. Crear Cliente con Vehículo y Conversación
        $customer = Customer::factory()
            ->has(Vehicle::factory()->count(1))
            ->has(Conversation::factory()->count(1))
            ->create();

        // 2. Recuperar las entidades creadas
        /** @var Vehicle $vehicle */
        $vehicle = $customer->vehicles->first();
        /** @var Conversation $conversation */
        $conversation = $customer->conversations->first();

        // 3. Vincular Vehículo a Conversación (Many-to-Many Pivot)
        $conversation->vehicles()->attach($vehicle->id, ['is_primary' => true]);

        $this->command->info('🔹 Entidades Base Creadas.');

        // 4. 🔥 SIMULACIÓN DE AGENTE: Iniciar Cotización
        // Llamamos al mismo servicio que usa el AgentToolAdapter.
        // Esto creará el Snapshot, la Quote 'pending' y disparará el Job de alternativas.
        $quote = $quoteService->createPendingQuote($conversation, $customer, $vehicle);

        // Output de confirmación
        $this->command->info('✅ Escenario Completo Generado:');
        $this->command->info("   Customer: {$customer->name} (ID: {$customer->id})");
        $this->command->info("   Vehicle:  {$vehicle->marca} {$vehicle->modelo} (ID: {$vehicle->id})");
        $this->command->info("   Quote:    #{$quote->id} (Status: {$quote->status})");

        // Aviso sobre colas
        if (config('queue.default') !== 'sync') {
            $this->command->warn("   ⚠️  La cola no es 'sync'. Ejecuta 'php artisan queue:work' para procesar las alternativas.");
        } else {
            $this->command->info('   ✨ Alternativas generadas (Cola Sync).');
        }
    }
}
