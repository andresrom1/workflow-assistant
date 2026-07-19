<?php

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
    $conversation = App\Models\Conversation::factory()->create();

    app(WhatsAppOutboundService::class)->sendDocumentMessage(
        '5491112345678',
        null,
        'https://r2.example.com/policy-documents/1/poliza.pdf',
        'poliza.pdf',
        $this->phoneNumberId,
        'Póliza 12345',
        $conversation->id,
    );

    expect(App\Models\Message::where('conversation_id', $conversation->id)->first())
        ->not->toBeNull()
        ->type->toBe(App\Enums\MessageType::Document)
        ->content->toBe('Póliza 12345')
        ->external_message_id->toBe('wamid.out001');
});
