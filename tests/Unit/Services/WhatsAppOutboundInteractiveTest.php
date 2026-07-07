<?php

use App\Models\Conversation;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['services.whatsapp.access_token' => 'test-token', 'services.whatsapp.api_version' => 'v21.0']);
});

it('sends the correct interactive button payload shape', function () {
    Http::fake([
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.btn001']]], 200),
    ]);

    $service = app(WhatsAppOutboundService::class);
    $service->sendInteractiveButtons(
        '5491112345678',
        null,
        'Elegí una opción',
        [['id' => 'alt:1', 'title' => 'Sancor $45K'], ['id' => 'alt:2', 'title' => 'Federación $48K']],
        '123456789',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['type'] === 'interactive'
            && $body['interactive']['type'] === 'button'
            && $body['interactive']['body']['text'] === 'Elegí una opción'
            && count($body['interactive']['action']['buttons']) === 2
            && $body['interactive']['action']['buttons'][0] === ['type' => 'reply', 'reply' => ['id' => 'alt:1', 'title' => 'Sancor $45K']]
            && $body['to'] === '5491112345678';
    });
});

it('truncates button titles to 20 characters', function () {
    Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.btn002']]], 200)]);

    $service = app(WhatsAppOutboundService::class);
    $service->sendInteractiveButtons(
        '5491112345678',
        null,
        'Body',
        [['id' => 'alt:1', 'title' => 'Un nombre de aseguradora carísimo de más de veinte caracteres']],
        '123456789',
    );

    Http::assertSent(function ($request) {
        $title = $request->data()['interactive']['action']['buttons'][0]['reply']['title'];

        return mb_strlen($title) <= 20;
    });
});

it('caps the buttons array to 3 even if more are passed', function () {
    Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.btn003']]], 200)]);

    $service = app(WhatsAppOutboundService::class);
    $service->sendInteractiveButtons(
        '5491112345678',
        null,
        'Body',
        [
            ['id' => 'a', 'title' => 'Uno'],
            ['id' => 'b', 'title' => 'Dos'],
            ['id' => 'c', 'title' => 'Tres'],
            ['id' => 'd', 'title' => 'Cuatro'],
        ],
        '123456789',
    );

    Http::assertSent(fn ($request) => count($request->data()['interactive']['action']['buttons']) === 3);
});

it('persists the outbound message as type interactive', function () {
    Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.btn004']]], 200)]);

    $conversation = Conversation::factory()->create();

    $service = app(WhatsAppOutboundService::class);
    $service->sendInteractiveButtons(
        '5491112345678',
        null,
        'Cuerpo del mensaje',
        [['id' => 'alt:1', 'title' => 'Opción']],
        '123456789',
        $conversation->id,
        'CheckoutAgent',
    );

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'type' => 'interactive',
        'content' => 'Cuerpo del mensaje',
        'agent_name' => 'CheckoutAgent',
        'external_message_id' => 'wamid.btn004',
    ]);
});
