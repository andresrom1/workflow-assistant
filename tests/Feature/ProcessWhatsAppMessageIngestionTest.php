<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessWhatsAppMessageIngestionTest extends TestCase
{
    use RefreshDatabase;

    private string $waId = '5491112345678';

    private string $messageId = 'wamid.test123';

    private string $phoneNumberId = '123456789';

    #[Test]
    public function it_persists_inbound_message_with_null_processed_at(): void
    {
        Bus::fake([ProcessConversationInbox::class]);

        $this->dispatchIngestion();

        $this->assertDatabaseHas('messages', [
            'external_message_id' => $this->messageId,
            'direction' => 'inbound',
            'content' => 'Hola quiero asegurar un auto',
            'processed_at' => null,
        ]);
    }

    #[Test]
    public function it_dispatches_inbox_processor_on_whatsapp_ai_queue(): void
    {
        Bus::fake([ProcessConversationInbox::class]);

        $this->dispatchIngestion();

        Bus::assertDispatched(ProcessConversationInbox::class, function ($job) {
            return $job->queue === 'whatsapp-ai';
        });
    }

    #[Test]
    public function it_does_not_dispatch_send_whatsapp_message(): void
    {
        Bus::fake([ProcessConversationInbox::class, SendWhatsAppMessage::class]);

        $this->dispatchIngestion();

        Bus::assertNotDispatched(SendWhatsAppMessage::class);
    }

    #[Test]
    public function it_ignores_duplicate_wamid(): void
    {
        Bus::fake([ProcessConversationInbox::class]);
        Cache::put('processed_wamid_'.$this->messageId, true, now()->addDay());

        $this->dispatchIngestion();

        $this->assertDatabaseMissing('messages', [
            'external_message_id' => $this->messageId,
        ]);

        Bus::assertNotDispatched(ProcessConversationInbox::class);
    }

    #[Test]
    public function it_skips_non_text_messages_without_dispatching_inbox(): void
    {
        Bus::fake([ProcessConversationInbox::class]);

        ProcessWhatsAppMessage::dispatchSync(
            waId: $this->waId,
            messageBody: '',
            messageId: $this->messageId,
            phoneNumberId: $this->phoneNumberId,
            contactName: 'Test User',
            messageType: 'image',
        );

        $this->assertDatabaseMissing('messages', [
            'external_message_id' => $this->messageId,
        ]);

        Bus::assertNotDispatched(ProcessConversationInbox::class);
    }

    #[Test]
    public function it_creates_conversation_if_not_exists(): void
    {
        Bus::fake([ProcessConversationInbox::class]);

        $this->assertDatabaseMissing('conversations', [
            'external_conversation_id' => $this->waId,
        ]);

        $this->dispatchIngestion();

        $this->assertDatabaseHas('conversations', [
            'external_conversation_id' => $this->waId,
            'channel' => 'whatsapp',
        ]);
    }

    #[Test]
    public function it_reuses_existing_conversation_for_same_wa_id(): void
    {
        Bus::fake([ProcessConversationInbox::class]);

        $this->dispatchIngestion();

        ProcessWhatsAppMessage::dispatchSync(
            waId: $this->waId,
            messageBody: 'Es un Renault Sandero',
            messageId: 'wamid.test456',
            phoneNumberId: $this->phoneNumberId,
            contactName: 'Test User',
        );

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    private function dispatchIngestion(): void
    {
        ProcessWhatsAppMessage::dispatchSync(
            waId: $this->waId,
            messageBody: 'Hola quiero asegurar un auto',
            messageId: $this->messageId,
            phoneNumberId: $this->phoneNumberId,
            contactName: 'Test User',
        );
    }
}
