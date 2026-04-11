<?php

use App\Services\Media\TextToSpeechService;
use Laravel\Ai\Audio;
use Tests\TestCase;

uses(TestCase::class);

it('returns binary content and mime type from TTS generation', function () {
    Audio::fake();

    $service = new TextToSpeechService;
    $result = $service->generate('Hola, te ayudo con tu cotización de seguro.');

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['content', 'mime_type']);
    expect($result['content'])->toBeString();
    expect($result['mime_type'])->toBeString()->not->toBeEmpty();

    Audio::assertGenerated(fn ($prompt) => $prompt->contains('Hola, te ayudo'));
});

it('defaults mime_type to audio/mpeg when provider returns null', function () {
    Audio::fake();

    $service = new TextToSpeechService;
    $result = $service->generate('Texto de prueba.');

    expect($result['mime_type'])->toBe('audio/mpeg');
});
