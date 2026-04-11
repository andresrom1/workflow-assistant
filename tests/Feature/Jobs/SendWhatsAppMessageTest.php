<?php

use App\Enums\Modality;
use App\Exceptions\WhatsAppSpamLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Media\MediaStorageService;
use App\Services\Media\TextToSpeechService;
use App\Services\Message\MessageModalityDecider;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->phoneNumberId = '123456789';
    $this->text = 'Perfecto, ya tengo todos tus datos. ¿Hay algo más en lo que te pueda ayudar hoy con tu cotización?';
});

// ---------------------------------------------------------------------------
// Typing indicator
// ---------------------------------------------------------------------------

it('always sends typing indicator before delivering any message', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')
        ->once()
        ->with($this->waId, $this->phoneNumberId);
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(['messages' => [['id' => 'wamid.out001']]]);

    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldReceive('decide')
        ->once()
        ->andReturn(['modality' => Modality::Text, 'eligible' => false, 'reason' => 'hard_gate', 'ratio' => null, 'p' => null, 'window_size' => null]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent');
});

// ---------------------------------------------------------------------------
// Text path
// ---------------------------------------------------------------------------

it('sends text and persists message with eligible flag', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')->once();
    $waService->shouldReceive('sendMessage')
        ->once()
        ->with($this->waId, $this->text, $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent', true)
        ->andReturn(['messages' => [['id' => 'wamid.out001']]]);

    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldReceive('decide')->andReturn([
        'modality' => Modality::Text, 'eligible' => true, 'reason' => 'band_ceiling', 'ratio' => 0.40, 'p' => null, 'window_size' => 5,
    ]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent');
});

// ---------------------------------------------------------------------------
// Audio path
// ---------------------------------------------------------------------------

it('generates TTS, stores in R2, uploads to Meta and sends audio message', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')->once();
    $waService->shouldReceive('uploadMedia')
        ->once()
        ->with('binary-audio-data', 'audio/mpeg', $this->phoneNumberId)
        ->andReturn('meta_media_id_abc');
    // sendAudioMessage now returns the persisted Message model.
    $outboundMessage = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'type' => 'audio',
        'audio_eligible' => true,
        'external_message_id' => 'wamid.audio_out001',
        'sender_phone' => $this->phoneNumberId,
    ]);

    $waService->shouldReceive('sendAudioMessage')
        ->once()
        ->with($this->waId, 'meta_media_id_abc', $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent', $this->text)
        ->andReturn($outboundMessage);

    $tts = $this->mock(TextToSpeechService::class);
    $tts->shouldReceive('generate')
        ->once()
        ->with($this->text)
        ->andReturn(['content' => 'binary-audio-data', 'mime_type' => 'audio/mpeg']);

    $storage = $this->mock(MediaStorageService::class);
    $storage->shouldReceive('store')
        ->once()
        ->with('binary-audio-data', 'audio', 'audio/mpeg')
        ->andReturn((object) ['path' => 'conversations/media/audio/test.mp3', 'url' => 'https://r2.test/test.mp3', 'size' => 512]);

    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldReceive('decide')->andReturn([
        'modality' => Modality::Audio, 'eligible' => true, 'reason' => 'band_floor', 'ratio' => 0.20, 'p' => null, 'window_size' => 5,
    ]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent');

    $this->assertDatabaseHas('message_attachments', [
        'attachment_type' => 'audio',
        'mime_type' => 'audio/mpeg',
        'storage_path' => 'conversations/media/audio/test.mp3',
        'processing_status' => 'done',
    ]);
});

// ---------------------------------------------------------------------------
// TTS failure fallback
// ---------------------------------------------------------------------------

it('falls back silently to text when TTS generation fails', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')->once();
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(['messages' => [['id' => 'wamid.fallback001']]]);
    $waService->shouldNotReceive('sendAudioMessage');

    $tts = $this->mock(TextToSpeechService::class);
    $tts->shouldReceive('generate')->once()->andThrow(new RuntimeException('OpenAI TTS error'));

    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldReceive('decide')->andReturn([
        'modality' => Modality::Audio, 'eligible' => true, 'reason' => 'band_floor', 'ratio' => 0.10, 'p' => null, 'window_size' => 3,
    ]);

    // Should not throw — fallback is silent
    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId, $conversation->id, 'CustomerIdentifierAgent');
});

// ---------------------------------------------------------------------------
// Spam limit
// ---------------------------------------------------------------------------

it('does not retry on WhatsApp spam limit exception', function () {
    $conversation = Conversation::factory()->create();

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')->once();
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andThrow(new WhatsAppSpamLimitException('Rate limit'));

    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldReceive('decide')->andReturn([
        'modality' => Modality::Text, 'eligible' => false, 'reason' => 'hard_gate', 'ratio' => null, 'p' => null, 'window_size' => null,
    ]);

    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId, $conversation->id);
});

// ---------------------------------------------------------------------------
// No conversation
// ---------------------------------------------------------------------------

it('sends text without modality decision when conversation id is null', function () {
    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')->once();
    $waService->shouldReceive('sendMessage')
        ->once()
        ->andReturn([]);

    // Decider should NOT be called when there's no conversation
    $decider = $this->mock(MessageModalityDecider::class);
    $decider->shouldNotReceive('decide');

    SendWhatsAppMessage::dispatchSync($this->waId, $this->text, $this->phoneNumberId);
});
