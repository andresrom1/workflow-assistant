<?php

use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->messageId = 'wamid.test123';
    $this->phoneNumberId = '123456789';
});

it('persists inbound message with null processed at', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'processed_at' => null,
    ]);
});

it('dispatches inbox processor on whatsapp ai queue', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    Bus::assertDispatched(ProcessConversationInbox::class, function ($job) {
        return $job->queue === 'whatsapp-ai';
    });
});

it('does not dispatch send whatsapp message', function () {
    Bus::fake([ProcessConversationInbox::class, SendWhatsAppMessage::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('ignores duplicate wamid', function () {
    Bus::fake([ProcessConversationInbox::class]);
    Cache::put('processed_wamid_'.$this->messageId, true, now()->addDay());

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseMissing('messages', [
        'external_message_id' => $this->messageId,
    ]);

    Bus::assertNotDispatched(ProcessConversationInbox::class);
});

it('skips non text messages without dispatching inbox', function () {
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
});

it('creates conversation if not exists', function () {
    Bus::fake([ProcessConversationInbox::class]);

    $this->assertDatabaseMissing('conversations', [
        'external_conversation_id' => $this->waId,
    ]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => $this->waId,
        'channel' => 'whatsapp',
    ]);
});

it('reuses existing conversation for same wa id', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: 'Es un Renault Sandero',
        messageId: 'wamid.test456',
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
    );

    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('messages', 2);
});

it('persists both phone and bsuid when both are present', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: 'Hola',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        extUserId: 'US.13491208655302741918',
    );

    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => $this->waId,
        'ext_user_id' => 'US.13491208655302741918',
        'channel' => 'whatsapp',
    ]);

    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'sender_phone' => $this->waId,
    ]);
});

it('ingests a bsuid-only message and routes the conversation by bsuid', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: null,
        messageBody: 'Hola, llego sin teléfono',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        extUserId: 'US.13491208655302741918',
    );

    // Sin teléfono, el BSUID es la clave de la conversación.
    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => 'US.13491208655302741918',
        'ext_user_id' => 'US.13491208655302741918',
        'channel' => 'whatsapp',
    ]);

    // El mensaje se persiste con sender_phone nulo.
    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'content' => 'Hola, llego sin teléfono',
        'sender_phone' => null,
    ]);

    Bus::assertDispatched(ProcessConversationInbox::class);
});

function dispatchIngestion(string $waId, string $messageId, string $phoneNumberId): void
{
    ProcessWhatsAppMessage::dispatchSync(
        waId: $waId,
        messageBody: 'Hola quiero asegurar un auto',
        messageId: $messageId,
        phoneNumberId: $phoneNumberId,
        contactName: 'Test User',
    );
}
