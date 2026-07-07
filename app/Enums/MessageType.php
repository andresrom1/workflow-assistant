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
    case Interactive = 'interactive';

    public function isMedia(): bool
    {
        return ! in_array($this, [self::Text, self::Interactive], true);
    }
}
