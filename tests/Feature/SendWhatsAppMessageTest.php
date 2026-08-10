<?php

use App\Enums\Modality;
use App\Exceptions\WhatsAppSpamLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Services\Message\MessageModalityDecider;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->bsuid = 'US.13491208655302741918';
    $this->phoneNumberId = '123456789';
});

it('calls outbound service with correct parameters', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendMessage')
        ->once()
        ->with($this->waId, $this->bsuid, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id, null, false, config('ai.default'))
        ->andReturn(['messages' => [['id' => 'wamid.out001']]]);

    // Decider returns TEXT (no inbound audio in this conversation)
    $this->mock(MessageModalityDecider::class)
        ->shouldReceive('decide')
        ->andReturn(['modality' => Modality::Text, 'eligible' => false, 'reason' => 'no_user_audio', 'ratio' => null, 'p' => null, 'window_size' => null]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->bsuid, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id);
});

it('calls outbound service without conversation id', function () {
    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendMessage')
        ->once()
        ->withArgs(fn ($phone, $bsuid, $text, $phoneId) => $phone === $this->waId && $text === 'Mensaje de prueba')
        ->andReturn([]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->bsuid, 'Mensaje de prueba', $this->phoneNumberId);
});

it('does not retry on spam limit exception', function () {
    $waService = $this->mock(WhatsAppOutboundService::class);

    // sendMessage se llama exactamente una vez — no hay reintentos
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andThrow(new WhatsAppSpamLimitException('Rate limit exceeded'));

    // dispatchSync con $this->fail() no re-lanza en tests; verificamos
    // que el servicio fue invocado exactamente una vez (sin reintentos).
    SendWhatsAppMessage::dispatchSync($this->waId, $this->bsuid, 'Mensaje', $this->phoneNumberId);
});
