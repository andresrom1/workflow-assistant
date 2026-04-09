<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Audio = 'audio';
    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Sticker = 'sticker';

    public function isMedia(): bool
    {
        return $this !== self::Text;
    }
}
