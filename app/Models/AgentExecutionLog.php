<?php

namespace App\Models;

use Database\Factories\AgentExecutionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentExecutionLog extends Model
{
    /** @use HasFactory<AgentExecutionLogFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'agent_name',
        'step',
        'state_before',
        'state_after',
        'state_changes',
        'chained',
        'status',
        'error_message',
        'duration_ms',
        'inbound_message_ids',
        'outbound_message_id',
        'input_tokens',
        'output_tokens',
        'tool_calls',
    ];

    protected function casts(): array
    {
        return [
            'state_before' => 'array',
            'state_after' => 'array',
            'state_changes' => 'array',
            'inbound_message_ids' => 'array',
            'tool_calls' => 'array',
            'chained' => 'boolean',
            'step' => 'integer',
            'duration_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'outbound_message_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }
}
