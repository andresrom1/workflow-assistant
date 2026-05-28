<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentExecutionLogAnnotation extends Model
{
    protected $fillable = [
        'agent_execution_log_id',
        'user_id',
        'verdict',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'verdict' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AgentExecutionLog, $this>
     */
    public function executionLog(): BelongsTo
    {
        return $this->belongsTo(AgentExecutionLog::class, 'agent_execution_log_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
