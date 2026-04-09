<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    /**
     * Store raw media content in R2 and return the storage metadata.
     *
     * @return object{ path: string, url: string, size: int }
     */
    public function store(string $content, string $attachmentType, string $mimeType): object
    {
        $ext = $this->extensionFromMime($mimeType);
        $path = "conversations/media/{$attachmentType}/".Str::uuid().".{$ext}";

        Storage::disk('r2')->put($path, $content);

        return (object) [
            'path' => $path,
            'url' => Storage::disk('r2')->url($path),
            'size' => strlen($content),
        ];
    }

    private function extensionFromMime(string $mimeType): string
    {
        $map = [
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'mp4',
            'audio/aac' => 'aac',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
        ];

        // Normalize: 'audio/ogg; codecs=opus' → 'audio/ogg'
        $base = strtolower(explode(';', $mimeType)[0]);

        return $map[$base] ?? 'bin';
    }
}
