<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'direction',
        'type',
        'agent_name',
        'content',
        'external_message_id',
        'sender_name',
        'sender_phone',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'processed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(MessageAttachment::class);
    }
}
