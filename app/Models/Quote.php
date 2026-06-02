<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'session_uuid',
        'risk_snapshot_id',
        'conversation_id',
        'status',                // 'pending', 'processed', 'failed', 'expired', 'checkout_pending', 'checkout_submitted'
        'external_ref_id',       // ID de correlación con el proveedor (Task ID)
        'resolution_method',     // 'api'
        'metadata',
        'expires_at',
        'checkout_token',
        'checkout_alternative_id',
    ];

    protected $casts = [
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

    public function checkoutSession(): HasOne
    {
        return $this->hasOne(CheckoutSession::class);
    }

    public function providerRef(): HasOne
    {
        return $this->hasOne(QuoteProviderRef::class);
    }
}
