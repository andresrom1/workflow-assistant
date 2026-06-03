<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Enums\MessageType;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessMediaAttachment;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Media\MediaStorageService;
use App\Services\Media\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->messageId = 'wamid.audio001';
    $this->phoneNumberId = '123456789';
    $this->mediaId = 'meta_media_id_abc123';
    $this->mimeType = 'audio/ogg; codecs=opus';
});

// =========================================================================
// ProcessWhatsAppMessage — audio ingestion
// =========================================================================

it('creates message with null content and attachment for audio type', function () {
    Bus::fake([ProcessConversationInbox::class, ProcessMediaAttachment::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: '',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        messageType: 'audio',
        mediaId: $this->mediaId,
        mediaMimeType: $this->mimeType,
    );

    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'direction' => 'inbound',
        'type' => 'audio',
        'content' => null,
        'processed_at' => null,
    ]);

    $this->assertDatabaseHas('message_attachments', [
        'external_media_id' => $this->mediaId,
        'attachment_type' => 'audio',
        'mime_type' => $this->mimeType,
        'processing_status' => 'pending',
    ]);
});

it('dispatches ProcessMediaAttachment on media queue for audio messages', function () {
    Bus::fake([ProcessConversationInbox::class, ProcessMediaAttachment::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: '',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        messageType: 'audio',
        mediaId: $this->mediaId,
        mediaMimeType: $this->mimeType,
    );

    Bus::assertDispatched(ProcessMediaAttachment::class, fn ($job) => $job->queue === 'media');
    Bus::assertNotDispatched(ProcessConversationInbox::class);
});

it('ignores unsupported media types', function () {
    Bus::fake([ProcessConversationInbox::class, ProcessMediaAttachment::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: '',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        messageType: 'image',
    );

    $this->assertDatabaseMissing('messages', ['external_message_id' => $this->messageId]);
    Bus::assertNotDispatched(ProcessConversationInbox::class);
    Bus::assertNotDispatched(ProcessMediaAttachment::class);
});

it('guards against empty or whitespace-only text messages', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: '   ',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        messageType: 'text',
    );

    $this->assertDatabaseMissing('messages', ['external_message_id' => $this->messageId]);
    Bus::assertNotDispatched(ProcessConversationInbox::class);
});

// =========================================================================
// ProcessMediaAttachment — transcription job
// =========================================================================

it('transcribes audio, updates message content, and dispatches inbox processor', function () {
    Bus::fake([ProcessConversationInbox::class]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => MessageType::Audio,
        'content' => null,
        'external_message_id' => $this->messageId,
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    $attachment = MessageAttachment::create([
        'message_id' => $message->id,
        'attachment_type' => 'audio',
        'external_media_id' => $this->mediaId,
        'mime_type' => $this->mimeType,
        'processing_status' => 'pending',
    ]);

    $this->mock(WhatsAppAdapter::class)
        ->shouldReceive('downloadMedia')
        ->with($this->mediaId)
        ->once()
        ->andReturn('fake-audio-bytes');

    $this->mock(MediaStorageService::class)
        ->shouldReceive('store')
        ->with('fake-audio-bytes', 'audio', $this->mimeType)
        ->once()
        ->andReturn((object) ['path' => 'conversations/media/audio/test.ogg', 'url' => 'https://r2.example.com/test.ogg', 'size' => 1024]);

    $this->mock(SpeechToTextService::class)
        ->shouldReceive('transcribe')
        ->with('conversations/media/audio/test.ogg')
        ->once()
        ->andReturn('Hola quiero asegurar un auto');

    ProcessMediaAttachment::dispatchSync($attachment->id, $conversation->id, $this->waId, $this->phoneNumberId);

    expect($message->fresh()->content)->toBe('Hola quiero asegurar un auto');

    $this->assertDatabaseHas('message_attachments', [
        'id' => $attachment->id,
        'processing_status' => 'done',
        'storage_path' => 'conversations/media/audio/test.ogg',
        'transcription' => 'Hola quiero asegurar un auto',
    ]);

    Bus::assertDispatched(ProcessConversationInbox::class, fn ($job) => $job->queue === 'whatsapp-ai');
});

it('marks attachment as failed and rethrows on download error', function () {
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => MessageType::Audio,
        'content' => null,
        'external_message_id' => $this->messageId,
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    $attachment = MessageAttachment::create([
        'message_id' => $message->id,
        'attachment_type' => 'audio',
        'external_media_id' => $this->mediaId,
        'mime_type' => $this->mimeType,
        'processing_status' => 'pending',
    ]);

    $this->mock(WhatsAppAdapter::class)
        ->shouldReceive('downloadMedia')
        ->once()
        ->andThrow(new RuntimeException('Meta API unreachable'));

    expect(fn () => ProcessMediaAttachment::dispatchSync($attachment->id, $conversation->id, $this->waId, $this->phoneNumberId))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('message_attachments', [
        'id' => $attachment->id,
        'processing_status' => 'failed',
        'error_message' => 'Meta API unreachable',
    ]);

    expect($message->fresh()->content)->toBeNull();
});

// =========================================================================
// ProcessConversationInbox — skips null-content messages
// =========================================================================

it('inbox skips audio messages with null content pending transcription', function () {
    Bus::fake();

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => MessageType::Audio,
        'content' => null,
        'external_message_id' => $this->messageId,
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);
});

it('inbox processes audio message once transcription has populated content', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with('Hola quiero asegurar un auto', Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Bienvenido', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => MessageType::Audio,
        'content' => 'Hola quiero asegurar un auto',
        'external_message_id' => $this->messageId,
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertDispatched(SendWhatsAppMessage::class);
});
