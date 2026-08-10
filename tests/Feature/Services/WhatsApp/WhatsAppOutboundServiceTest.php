<?php

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->phoneNumberId = '123456789';
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out001']]], 200),
    ]);
});

it('sends to the phone via the to field when a phone is present', function () {
    app(WhatsAppOutboundService::class)->sendMessage(
        '5491112345678',
        'US.13491208655302741918',
        'Hola',
        $this->phoneNumberId,
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['to'] ?? null) === '5491112345678'
            && ! array_key_exists('recipient', $body);
    });
});

it('sends to the bsuid via the recipient field when there is no phone', function () {
    app(WhatsAppOutboundService::class)->sendMessage(
        null,
        'US.13491208655302741918',
        'Hola',
        $this->phoneNumberId,
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['recipient'] ?? null) === 'US.13491208655302741918'
            && ! array_key_exists('to', $body);
    });
});

it('sends a document message with link, filename and caption', function () {
    app(WhatsAppOutboundService::class)->sendDocumentMessage(
        '5491112345678',
        null,
        'https://r2.example.com/policy-documents/1/poliza.pdf',
        'poliza.pdf',
        $this->phoneNumberId,
        'Póliza 12345 — Mercantil Andina',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['to'] ?? null) === '5491112345678'
            && ($body['type'] ?? null) === 'document'
            && ($body['document']['link'] ?? null) === 'https://r2.example.com/policy-documents/1/poliza.pdf'
            && ($body['document']['filename'] ?? null) === 'poliza.pdf'
            && ($body['document']['caption'] ?? null) === 'Póliza 12345 — Mercantil Andina';
    });
});

it('sends a document message without a caption key when none is given', function () {
    app(WhatsAppOutboundService::class)->sendDocumentMessage(
        '5491112345678',
        null,
        'https://r2.example.com/policy-documents/1/poliza.pdf',
        'poliza.pdf',
        $this->phoneNumberId,
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['type'] ?? null) === 'document'
            && ! array_key_exists('caption', $body['document'] ?? []);
    });
});

it('persists an outbound Message with type document when a conversationId is given', function () {
    $conversation = Conversation::factory()->create();

    app(WhatsAppOutboundService::class)->sendDocumentMessage(
        '5491112345678',
        null,
        'https://r2.example.com/policy-documents/1/poliza.pdf',
        'poliza.pdf',
        $this->phoneNumberId,
        'Póliza 12345',
        $conversation->id,
    );

    expect(Message::where('conversation_id', $conversation->id)->first())
        ->not->toBeNull()
        ->type->toBe(MessageType::Document)
        ->content->toBe('Póliza 12345')
        ->external_message_id->toBe('wamid.out001');
});

// ---------------------------------------------------------------------------
// Typing indicator
// ---------------------------------------------------------------------------

/**
 * La Cloud API empaqueta el indicador con el acuse de lectura, así que se ancla al id del
 * mensaje ENTRANTE y no a un destinatario. Meta lo sostiene 25s como máximo, o hasta que
 * mandemos la respuesta.
 *
 * La versión anterior mandaba una `reaction` vacía (`message_id: ''`) — un truco de antes de
 * que Meta sacara la feature. Meta la rechaza, y el `catch` mudo se comía el error: por eso
 * nadie notó durante meses que el indicador no se mostraba nunca.
 */
it('marks the inbound message as read and shows the typing bubble', function () {
    app(WhatsAppOutboundService::class)->sendTypingIndicator('wamid.entrante001', $this->phoneNumberId);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['status'] ?? null) === 'read'
            && ($body['message_id'] ?? null) === 'wamid.entrante001'
            && ($body['typing_indicator']['type'] ?? null) === 'text'
            && ! array_key_exists('type', $body)
            && ! array_key_exists('reaction', $body);
    });
});

it('never lets a failed typing indicator break the turn', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400),
    ]);

    app(WhatsAppOutboundService::class)->sendTypingIndicator('wamid.entrante002', $this->phoneNumberId);
})->throwsNoExceptions();
