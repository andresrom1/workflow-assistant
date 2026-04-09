<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;

class SpeechToTextService
{
    /**
     * Transcribe an audio file stored on R2 and return the transcript text.
     */
    public function transcribe(string $storagePath): string
    {
        $content = Storage::disk('r2')->get($storagePath);

        $tmpPath = tempnam(sys_get_temp_dir(), 'stt_').'.'.pathinfo($storagePath, PATHINFO_EXTENSION);

        try {
            file_put_contents($tmpPath, $content);

            $transcript = Transcription::fromPath($tmpPath)->generate();

            return (string) $transcript;
        } finally {
            @unlink($tmpPath);
        }
    }
}
