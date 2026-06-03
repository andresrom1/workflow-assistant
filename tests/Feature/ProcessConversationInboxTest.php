<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->phoneNumberId = '123456789';
});

it('processes single message and dispatches outbound', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with('Hola quiero asegurar un auto', Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Perfecto, ¿qué vehículo querés asegurar?', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
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

it('concatenates multiple messages with newline', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with("Hola quiero asegurar un auto\nEs un Renault Sandero", Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Anotado el Renault Sandero. ¿Qué cobertura preferís?', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
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
