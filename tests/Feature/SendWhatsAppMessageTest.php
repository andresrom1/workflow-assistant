<?php

use App\Exceptions\WhatsAppSpamLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->phoneNumberId = '123456789';
});

it('calls outbound service with correct parameters', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendMessage')
        ->once()
        ->with($this->waId, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id, null)
        ->andReturn(['messages' => [['id' => 'wamid.out001']]]);

    SendWhatsAppMessage::dispatchSync($this->waId, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id);
});

it('calls outbound service without conversation id', function () {
    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendMessage')
        ->once()
        ->with($this->waId, 'Mensaje de prueba', $this->phoneNumberId, null, null)
        ->andReturn([]);

    SendWhatsAppMessage::dispatchSync($this->waId, 'Mensaje de prueba', $this->phoneNumberId);
});

it('does not retry on spam limit exception', function () {
    $waService = $this->mock(WhatsAppOutboundService::class);

    // sendMessage se llama exactamente una vez — no hay reintentos
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andThrow(new WhatsAppSpamLimitException('Rate limit exceeded'));

    // dispatchSync con $this->fail() no re-lanza en tests; verificamos
    // que el servicio fue invocado exactamente una vez (sin reintentos).
    SendWhatsAppMessage::dispatchSync($this->waId, 'Mensaje', $this->phoneNumberId);
});
