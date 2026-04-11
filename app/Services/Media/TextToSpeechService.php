<?php

namespace App\Services\Media;

use Laravel\Ai\Audio;

class TextToSpeechService
{
    /**
     * Generate speech audio from text and return the binary mp3 content.
     *
     * @return array{content: string, mime_type: string}
     */
    public function generate(string $text): array
    {
        $response = Audio::of($text)
            ->female()
            ->instructions('Speak naturally, with slight pauses between ideas, as if explaining to a friend')
            ->generate();

        return [
            'content' => $response->content(),
            'mime_type' => $response->mimeType() ?? 'audio/mpeg',
        ];
    }
}
