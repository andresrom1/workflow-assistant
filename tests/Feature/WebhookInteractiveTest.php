<?php

use App\Enums\MessageType;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// NOTA: WhatsAppWebhookController::handleIncoming() valida HMAC leyendo
// file_get_contents('php://input'), que el test client de Laravel no puebla
// (construye el Request en memoria, sin pasar por el stream real de PHP) —
// no es testeable vía HTTP feature test con este harness, independientemente
// de este cambio. Estos tests cubren el mismo mapeo (type=interactive →
// content=button_reply.title, sin mediaId/mime) a nivel del Job, que es donde
// vive la lógica que introduce WS2.

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->phoneNumberId = '123456789';
});

it('processes an interactive button reply as a normal text message', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        $this->waId,
        'Sancor $45K', // el webhook extrae esto de interactive.button_reply.title
        'wamid.interactive_webhook001',
        $this->phoneNumberId,
        'Cliente Test',
        'interactive',
        null,
        null,
        null, // mediaId: null para interactive
        null, // mediaMimeType: null para interactive
    );

    $this->assertDatabaseHas('messages', [
        'external_message_id' => 'wamid.interactive_webhook001',
        'direction' => 'inbound',
        'type' => 'interactive',
        'content' => 'Sancor $45K',
    ]);

    $conversation = Conversation::where('external_conversation_id', $this->waId)->first();
    expect($conversation)->not->toBeNull();

    Bus::assertDispatched(ProcessConversationInbox::class);
});

it('ignores an interactive reply with an empty title', function () {
    ProcessWhatsAppMessage::dispatchSync(
        $this->waId,
        '',
        'wamid.interactive_webhook002',
        $this->phoneNumberId,
        'Cliente Test',
        'interactive',
    );

    $this->assertDatabaseMissing('messages', ['external_message_id' => 'wamid.interactive_webhook002']);
});

it('does not treat interactive messages as media for TTS eligibility purposes', function () {
    expect(MessageType::Interactive->isMedia())->toBeFalse();
});
