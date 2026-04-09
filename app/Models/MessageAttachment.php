<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'attachment_type',
        'external_media_id',
        'mime_type',
        'file_size',
        'duration_seconds',
        'storage_path',
        'storage_url',
        'transcription',
        'processing_status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'file_size' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
