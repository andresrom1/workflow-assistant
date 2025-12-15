<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'risk_snapshot_id',
        'conversation_id',
        'status',                // 'pending', 'processed', 'failed', 'expired'
        'external_ref_id',       // ID de correlación con el proveedor (Task ID)
        'raw_response', // JSON crudo para auditoría
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'raw_response' => 'array', // Cast automático JSON -> Array
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function riskSnapshot(): BelongsTo
    {
        return $this->belongsTo(RiskSnapshot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function alternatives(): HasMany
    {
        return $this->hasMany(QuoteAlternative::class);
    }
}