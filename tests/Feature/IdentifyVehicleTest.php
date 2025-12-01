<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Log;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class IdentifyVehicleTest extends TestCase
{
    use RefreshDatabase;

    private string $threadId = 'thread_abc123xyz';
    private string $openaiUserId = '01e40f5f-b311-4365-8587-c14f1543aa51';

    protected function setUp(): void
    {
        parent::setUp();

        // config(['logging.default' => 'stack']);
        
        // // Setup: Create active conversation with customer
        // $customer = Customer::create([
        //     'dni' => '12345678',
        //     'name' => 'Test User',
        // ]);

        // $conversation = Conversation::create([
        //     'thread_id' => $this->threadId,
        //     'openai_user_id' => $this->openaiUserId,
        //     'customer_id' => $customer->id,
        //     'status' => 'active',
        //     'started_at' => now(),
        //     'last_activity' => now(),
        // ]);
    }

    #[Test]
    public function it_creates_a_vehicle_when_customer_is_identified()
    {
        // 1. Arrange: Preparamos el escenario (Cliente identificado en el chat)
        $threadId = 'cthr_test_vehicle_01';
        $userId = 'user_test_01';

        $customer = Customer::factory()->create([
            'email' => 'juan@test.com',
            'name' => 'Juan Perez'
        ]);
        Log::info('[TEST] Created customer', ['customer_id' => $customer->id]);

        // La conversación DEBE tener customer_id para que vehicle funcione
        $conversation = Conversation::create([
            'external_conversation_id' => $threadId,
            'external_user_id' => $userId,
            'customer_id' => $customer->id, 
            'status' => 'identified',
            'last_message_at' => now()
        ]);

        // Payload estricto según schema de OpenAI
        $payload = [
            // Protocolo
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            
            // Datos del Tool identify_vehicle
            'patente' => 'AD 123 CC', // Espaciada para probar normalización
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'version' => 'XEI CVT',
            'year' => 2022, // Mapeo a 'year'
            'combustible' => 'Nafta', // Case insensitive test
            'codigo_postal' => '5000'
        ];

        // 2. Act
        $response = $this->postJson('/api/tools/identify-vehicle', $payload);

        // 3. Assert HTTP & JSON
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                // Verificamos parte del string de salida blindada
                'tool_output' => 'Vehículo registrado correctamente.'
            ]);

        // 4. Assert Database (Normalización y Relaciones)
        $this->assertDatabaseHas('vehicles', [
            'customer_id' => $customer->id,
            'patente' => 'AD123CC', // Debe estar sin espacios y mayúscula
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'version' => 'XEI CVT',
            'year' => 2022,
            'combustible' => 'nafta', // Debe estar en minúsculas (enum)
            'codigo_postal' => '5000'
        ]);
    }

    #[Test]
    public function it_updates_existing_vehicle_owner_and_data()
    {
        // Escenario: El auto existe pero con datos viejos o dueño anterior
        $threadId = 'cthr_test_vehicle_02';
        $userId = 'user_test_02';
        
        // Cliente NUEVO (Juan)
        $newCustomer = Customer::factory()->create(['email' => 'juan@test.com']);
        
        // Vehículo existente (pertenece a otro o datos viejos)
        $existingVehicle = Vehicle::create([
            'customer_id' => Customer::factory()->create()->id, // Dueño anterior
            'patente' => 'AA123BB',
            'marca' => 'Ford',
            'modelo' => 'Fiesta',
            'version' => 'S',
            'year' => 2015,
            'combustible' => 'nafta',
            'codigo_postal' => '1000'
        ]);

        Conversation::create([
            'external_conversation_id' => $threadId,
            'external_user_id' => $userId,
            'customer_id' => $newCustomer->id, // Juan está chateando
        ]);

        $payload = [
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            'patente' => 'AA123BB', // Misma patente
            'marca' => 'Ford',
            'modelo' => 'Fiesta',
            'version' => 'Titanium', // Dato enriquecido
            'year' => 2015,
            'combustible' => 'GNC', // Cambio técnico (agregó equipo)
            'codigo_postal' => '2000' // Mudanza
        ];

        $this->postJson('/api/tools/identify-vehicle', $payload)->assertStatus(200);

        // Verificamos transferencia y actualización
        $this->assertDatabaseHas('vehicles', [
            'id' => $existingVehicle->id,
            'customer_id' => $newCustomer->id, // ¡Dueño cambiado!
            'version' => 'Titanium', // Actualizado
            // 'combustible' => 'gnc', // Si decidiste permitir update de combustible en tu política
            'codigo_postal' => '2000' // Actualizado
        ]);
    }

    #[Test]
    public function it_fails_if_customer_is_not_identified_yet()
    {
        // Escenario: Usuario anónimo intenta cargar auto
        $threadId = 'cthr_anonymous';
        $userId = 'user_anon';

        Conversation::create([
            'external_conversation_id' => $threadId,
            'external_user_id' => $userId,
            'customer_id' => null, // <--- ANÓNIMO
        ]);

        $payload = [
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            'patente' => 'ZZ999ZZ',
            'marca' => 'Fiat',
            'modelo' => 'Uno',
            'version' => 'Way',
            'year' => 2010,
            'combustible' => 'Nafta',
            'codigo_postal' => '1000'
        ];

        $response = $this->postJson('/api/tools/identify-vehicle', $payload);

        // Esperamos que el Adapter rechace la operación
        $response->assertStatus(200) // HTTP OK porque es un error lógico para la IA
            ->assertJson([
                'success' => false,
                'error_code' => 'missing_customer'
            ]);
            
        $this->assertDatabaseMissing('vehicles', ['patente' => 'ZZ999ZZ']);
    }

    #[Test]
    public function it_creates_new_vehicle_with_valid_data()
    {
        $customer = Customer::first();
        $conversation = Conversation::first();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversations', 1);

        // Act: Send request to identify vehicle
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol Trend',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'Nafta',
            'codigo_postal' => '5000',
            'thread_id' => $conversation->thread_id,
            'customer_id' => $customer->id,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        $this->assertDatabaseCount('conversations', 1);
        // Assert: Response is successful
        
        // $response->assertStatus(200)
        //     ->assertJson([
        //         'success' => true,
        //         'message' => 'Vehículo identificado correctamente',
        //     ]);

        // Assert: Vehicle was created in database
        $this->assertDatabaseHas('vehicles', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol Trend',
        ]);
    }

    #[Test]
    public function it_normalizes_plate_correctly()
    {
        // Act: Send plate with lowercase and spaces
        $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'abc 123',
            'marca' => 'Ford',
            'modelo' => 'Focus',
            'version' => '2.0',
            'anio' => 2019,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Plate is stored normalized (uppercase, no spaces)
        $this->assertDatabaseHas('vehicles', [
            'patente' => 'ABC123',
        ]);
    }

    #[Test]
    public function it_finds_existing_vehicle_by_plate()
    {
        // Arrange: Create existing vehicle
        Vehicle::create([
            'patente' => 'XYZ789',
            'marca' => 'Fiat',
            'modelo' => 'Palio',
            'version' => '1.4',
            'year' => 2018,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
        ]);

        // Act: Try to identify same vehicle
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'XYZ789',
            'marca' => 'Fiat',
            'modelo' => 'Palio',
            'version' => '1.4',
            'anio' => 2018,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Response is successful
        $response->assertStatus(200);
        
        // Assert: Only one vehicle with that plate exists (no duplicate created)
        $this->assertEquals(1, Vehicle::where('patente', 'XYZ789')->count());
    }

    #[Test]
    public function it_rejects_invalid_plate_format()
    {
        // Act: Send invalid plate format
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => '12345', // Invalid: only numbers
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Returns validation error
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'validation_error',
            ]);
    }

    #[Test]
    public function it_accepts_old_format_plate()
    {
        // Act: Send old format plate (AAA000)
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Accepted
        $response->assertStatus(200);
    }

    #[Test]
    public function it_accepts_new_format_plate()
    {
        // Act: Send new format plate (AA000AA)
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'AB123CD',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Accepted
        $response->assertStatus(200);
        $this->assertDatabaseHas('vehicles', ['patente' => 'AB123CD']);
    }

    #[Test]
    public function it_rejects_invalid_fuel_type()
    {
        // Act: Send invalid fuel type
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'agua', // Invalid
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Returns validation error
        $response->assertStatus(422);
    }

    #[Test]
    public function it_links_vehicle_to_conversation_customer()
    {
        // Act: Identify vehicle
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Vehicle is linked to customer from conversation
        $response->assertStatus(200);

        $vehicle = Vehicle::where('patente', 'ABC123')->first();
        $conversation = Conversation::where('thread_id', $this->threadId)->first();

        $this->assertEquals($conversation->customer_id, $vehicle->customer_id);
    }

    #[Test]
    public function it_fails_without_openai_user_id()
    {
        // Act: Send request without openai_user_id
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ]);

        // Assert: Returns 400 error
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'OpenAI User ID is required',
            ]);
    }

    #[Test]
    public function it_fails_without_thread_id()
    {
        // Act: Send request without thread_id
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Returns validation error (thread_id is required)
        $response->assertStatus(422);
    }

    #[Test]
    public function it_checks_vehicle_completeness()
    {
        // Act: Identify vehicle with all required fields
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Vehicle is marked as complete
        $response->assertStatus(200)
            ->assertJson([
                'is_complete' => true,
            ]);

        $vehicle = Vehicle::where('patente', 'ABC123')->first();
        $this->assertTrue($vehicle->is_complete);
    }

    #[Test]
    public function it_returns_next_step_for_complete_vehicle()
    {
        // Act: Identify complete vehicle
        $response = $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: Next step is coverage_selection
        $response->assertStatus(200)
            ->assertJson([
                'next_step' => 'coverage_selection',
            ]);
    }

    #[Test]
    public function it_updates_conversation_activity()
    {
        // Arrange: Get initial last_activity timestamp
        $conversation = Conversation::where('thread_id', $this->threadId)->first();
        $initialActivity = $conversation->last_activity;

        // Wait 1 second to ensure timestamp difference
        sleep(1);

        // Act: Identify vehicle
        $this->postJson('/api/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'anio' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
        ], [
            'X-OpenAI-User-ID' => $this->openaiUserId,
        ]);

        // Assert: last_activity was updated
        $conversation->refresh();
        $this->assertNotEquals($initialActivity, $conversation->last_activity);
    }
}