<?php

namespace Tests\Feature;

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    private string $waId = '5491112345678';

    private string $phoneNumberId = '123456789';

    #[Test]
    public function it_processes_single_message_and_dispatches_outbound(): void
    {
        Bus::fake([SendWhatsAppMessage::class]);

        $orchestrator = $this->mock(InsuranceOrchestrator::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->with('Hola quiero asegurar un auto', \Mockery::type(Conversation::class))
            ->andReturn(['text' => 'Perfecto, ¿qué vehículo querés asegurar?', 'agent' => 'CustomerIdentifierAgent']);

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
    }

    #[Test]
    public function it_concatenates_multiple_messages_with_newline(): void
    {
        Bus::fake([SendWhatsAppMessage::class]);

        $orchestrator = $this->mock(InsuranceOrchestrator::class);
        $orchestrator->shouldReceive('handle')
            ->once()
            ->with("Hola quiero asegurar un auto\nEs un Renault Sandero", \Mockery::type(Conversation::class))
            ->andReturn(['text' => 'Anotado el Renault Sandero. ¿Qué cobertura preferís?', 'agent' => 'VehicleIdentifierAgent']);

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
    }

    #[Test]
    public function it_marks_messages_processed_before_calling_ai(): void
    {
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

                return ['text' => 'Respuesta', 'agent' => 'CustomerIdentifierAgent'];
            });

        ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

        $this->assertNotNull($processedAtDuringAiCall, 'processed_at debería estar seteado antes de llamar al AI');
    }

    #[Test]
    public function it_exits_cleanly_when_inbox_is_empty(): void
    {
        Bus::fake([SendWhatsAppMessage::class]);

        $orchestrator = $this->mock(InsuranceOrchestrator::class);
        $orchestrator->shouldNotReceive('handle');

        $conversation = Conversation::factory()->create([
            'external_conversation_id' => $this->waId,
        ]);

        ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    #[Test]
    public function it_does_not_reprocess_already_processed_messages(): void
    {
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
    }

    #[Test]
    public function it_ignores_outbound_messages_in_inbox_query(): void
    {
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
    }
}
