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
