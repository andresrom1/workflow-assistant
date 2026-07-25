<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->bsuid = 'US.13491208655302741918';
    $this->phoneNumberId = '123456789';
});

it('processes single message and dispatches outbound', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with('Hola quiero asegurar un auto', Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Perfecto, ¿qué vehículo querés asegurar?', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    // Sin nombre → no se antepone contexto; el body llega tal cual.
    $customer = Customer::factory()->create(['name' => null]);
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => $customer->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'external_message_id' => 'wamid.test001',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertNotNull($message->fresh()->processed_at);

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        return $job->queue === 'whatsapp-outbound';
    });
});

it('dispatches outbound with the bsuid as recipient when there is no phone', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Hola, ¿en qué te ayudo?', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    // Conversación solo-BSUID: external_conversation_id == ext_user_id == BSUID.
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->bsuid,
        'ext_user_id' => $this->bsuid,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.bsuid001',
        'sender_name' => 'Test User',
        'sender_phone' => null,
    ]);

    // Sin teléfono fresco del webhook.
    ProcessConversationInbox::dispatchSync($conversation->id, null, $this->phoneNumberId);

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        $ref = new ReflectionClass($job);
        $phone = tap($ref->getProperty('phone'), fn ($p) => $p->setAccessible(true))->getValue($job);
        $bsuid = tap($ref->getProperty('bsuid'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return $phone === null && $bsuid === $this->bsuid;
    });
});

it('concatenates multiple messages with newline', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with("Hola quiero asegurar un auto\nEs un Renault Sandero", Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Anotado el Renault Sandero. ¿Qué cobertura preferís?', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []]);

    // Sin nombre → no se antepone contexto; el body concatenado llega tal cual.
    $customer = Customer::factory()->create(['name' => null]);
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => $customer->id,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'external_message_id' => 'wamid.test002',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Es un Renault Sandero',
        'external_message_id' => 'wamid.test003',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertDatabaseMissing('messages', [
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'processed_at' => null,
    ]);
});

it('marks messages processed before calling ai', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.test004',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    $processedAtDuringAiCall = null;

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function () use ($message, &$processedAtDuringAiCall) {
            $processedAtDuringAiCall = $message->fresh()->processed_at;

            return ['text' => 'Respuesta', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertNotNull($processedAtDuringAiCall, 'processed_at debería estar seteado antes de llamar al AI');
});

it('exits cleanly when inbox is empty', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('does not reprocess already processed messages', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Mensaje ya procesado',
        'external_message_id' => 'wamid.test005',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
        'processed_at' => now()->subMinutes(5),
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('prepends the customer name to the body on the first turn (for the greeting)', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $captured = null;
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturnUsing(function (string $body) use (&$captured) {
        $captured = $body;

        return ['text' => 'Hola Juan', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
    });

    $customer = Customer::factory()->create(['name' => 'Juan Perez']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.name001',
        'sender_name' => 'Juan Perez',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($captured)->toContain('Juan Perez')->toContain('Hola');
});

it('does not prepend the name once the agent memory thread already exists', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $captured = null;
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturnUsing(function (string $body) use (&$captured) {
        $captured = $body;

        return ['text' => 'Dale', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []];
    });

    $customer = Customer::factory()->create(['name' => 'Juan Perez']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    // Ya hay hilo de memoria para esta conversación → no es el primer turno.
    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $conversation->id,
        'title' => 'hilo previo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Es un Gol',
        'external_message_id' => 'wamid.name002',
        'sender_name' => 'Juan Perez',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($captured)->toBe('Es un Gol');
});

it('ignores outbound messages in inbox query', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    // Solo mensaje outbound sin procesar — no debe disparar AI
    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'content' => 'Respuesta anterior del bot',
        'external_message_id' => 'wamid.outbound001',
        'sender_name' => 'Bot',
        'sender_phone' => $this->phoneNumberId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});
