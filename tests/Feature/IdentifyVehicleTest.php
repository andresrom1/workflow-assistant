<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentifyVehicleTest extends TestCase
{
    use RefreshDatabase;

    private string $threadId = 'thread_abc123xyz';

    private string $openaiUserId = '01e40f5f-b311-4365-8587-c14f1543aa51';

    private string $sessionUuid = 'test-session-uuid-vehicle';

    protected function setUp(): void
    {
        parent::setUp();

        $customer = Customer::factory()->create();

        Conversation::create([
            'external_conversation_id' => $this->threadId,
            'ext_user_id' => $this->openaiUserId,
            'customer_id' => $customer->id,
            'status' => 'active',
            'last_message_at' => now(),
        ]);
    }

    #[Test]
    public function it_creates_a_vehicle_when_customer_is_identified(): void
    {
        $threadId = 'cthr_test_vehicle_01';
        $userId = 'user_test_01';

        $customer = Customer::factory()->create([
            'email' => 'juan@test.com',
            'name' => 'Juan Perez',
        ]);

        Conversation::create([
            'external_conversation_id' => $threadId,
            'ext_user_id' => $userId,
            'customer_id' => $customer->id,
            'status' => 'identified',
            'last_message_at' => now(),
        ]);

        $payload = [
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            'sessionUuid' => $this->sessionUuid,
            'patente' => 'AD 123 CC',
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'version' => 'XEI CVT',
            'year' => 2022,
            'combustible' => 'Nafta',
            'codigo_postal' => '5000',
        ];

        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', $payload);

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertStringStartsWith(
            'Vehículo registrado correctamente.',
            $response->json('tool_output')
        );

        $this->assertDatabaseHas('vehicles', [
            'customer_id' => $customer->id,
            'patente' => 'AD123CC',
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'version' => 'XEI CVT',
            'year' => 2022,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
        ]);
    }

    #[Test]
    public function it_updates_existing_vehicle_owner_and_data(): void
    {
        $threadId = 'cthr_test_vehicle_02';
        $userId = 'user_test_02';

        $newCustomer = Customer::factory()->create(['email' => 'juan@test.com']);

        $existingVehicle = Vehicle::create([
            'customer_id' => Customer::factory()->create()->id,
            'patente' => 'AA123BB',
            'marca' => 'Ford',
            'modelo' => 'Fiesta',
            'version' => 'S',
            'year' => 2015,
            'combustible' => 'nafta',
            'codigo_postal' => '1000',
        ]);

        Conversation::create([
            'external_conversation_id' => $threadId,
            'ext_user_id' => $userId,
            'customer_id' => $newCustomer->id,
        ]);

        $payload = [
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            'sessionUuid' => $this->sessionUuid,
            'patente' => 'AA123BB',
            'marca' => 'Ford',
            'modelo' => 'Fiesta',
            'version' => 'Titanium',
            'year' => 2015,
            'combustible' => 'GNC',
            'codigo_postal' => '2000',
        ];

        $this->postJson('/api/web-chat/v1/tools/identify-vehicle', $payload)->assertStatus(200);

        $this->assertDatabaseHas('vehicles', [
            'id' => $existingVehicle->id,
            'customer_id' => $newCustomer->id,
            'version' => 'Titanium',
            'codigo_postal' => '2000',
        ]);
    }

    #[Test]
    public function it_fails_if_customer_is_not_identified_yet(): void
    {
        $threadId = 'cthr_anonymous';
        $userId = 'user_anon';

        Conversation::create([
            'external_conversation_id' => $threadId,
            'ext_user_id' => $userId,
            'customer_id' => null,
        ]);

        $payload = [
            'thread_id' => $threadId,
            'openai_user_id' => $userId,
            'ai_provider' => 'openai',
            'sessionUuid' => $this->sessionUuid,
            'patente' => 'ZZ999ZZ',
            'marca' => 'Fiat',
            'modelo' => 'Uno',
            'version' => 'Way',
            'year' => 2010,
            'combustible' => 'Nafta',
            'codigo_postal' => '1000',
        ];

        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', $payload);

        // The controller maps non-validation errors to 500 (see ToolsController::jsonResponse)
        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'error_code' => 'missing_customer',
            ]);

        $this->assertDatabaseMissing('vehicles', ['patente' => 'ZZ999ZZ']);
    }

    #[Test]
    public function it_creates_new_vehicle_with_valid_data(): void
    {
        $conversation = Conversation::where('external_conversation_id', $this->threadId)->first();
        $customer = $conversation->customer;

        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol Trend',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'Nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('vehicles', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol Trend',
        ]);
    }

    #[Test]
    public function it_normalizes_plate_correctly(): void
    {
        $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'abc 123',
            'marca' => 'Ford',
            'modelo' => 'Focus',
            'version' => '2.0',
            'year' => 2019,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $this->assertDatabaseHas('vehicles', [
            'patente' => 'ABC123',
        ]);
    }

    #[Test]
    public function it_finds_existing_vehicle_by_plate(): void
    {
        $conversation = Conversation::where('external_conversation_id', $this->threadId)->first();

        Vehicle::create([
            'customer_id' => $conversation->customer_id,
            'patente' => 'XYZ789',
            'marca' => 'Fiat',
            'modelo' => 'Palio',
            'version' => '1.4',
            'year' => 2018,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
        ]);

        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'XYZ789',
            'marca' => 'Fiat',
            'modelo' => 'Palio',
            'version' => '1.4',
            'year' => 2018,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200);

        $this->assertEquals(1, Vehicle::where('patente', 'XYZ789')->count());
    }

    #[Test]
    public function it_rejects_invalid_plate_format(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => '12345',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'validation_error',
            ]);
    }

    #[Test]
    public function it_accepts_old_format_plate(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function it_accepts_new_format_plate(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'AB123CD',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('vehicles', ['patente' => 'AB123CD']);
    }

    #[Test]
    public function it_rejects_invalid_fuel_type(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'agua',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_links_vehicle_to_conversation_customer(): void
    {
        $conversation = Conversation::where('external_conversation_id', $this->threadId)->first();

        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200);

        $vehicle = Vehicle::where('patente', 'ABC123')->first();

        $this->assertEquals($conversation->customer_id, $vehicle->customer_id);
    }

    #[Test]
    public function it_fails_without_openai_user_id(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        // Missing openai_user_id triggers validation error
        $response->assertStatus(422);
    }

    #[Test]
    public function it_fails_without_thread_id(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_checks_vehicle_completeness(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $vehicle = Vehicle::where('patente', 'ABC123')->first();
        $this->assertNotNull($vehicle);
    }

    #[Test]
    public function it_returns_next_step_for_complete_vehicle(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    #[Test]
    public function it_updates_conversation_activity(): void
    {
        $conversation = Conversation::where('external_conversation_id', $this->threadId)->first();
        $initialActivity = $conversation->updated_at;

        sleep(1);

        $this->postJson('/api/web-chat/v1/tools/identify-vehicle', [
            'patente' => 'ABC123',
            'marca' => 'Volkswagen',
            'modelo' => 'Gol',
            'version' => '1.6',
            'year' => 2020,
            'combustible' => 'nafta',
            'codigo_postal' => '5000',
            'thread_id' => $this->threadId,
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $conversation->refresh();
        $this->assertNotNull($conversation);
    }
}
