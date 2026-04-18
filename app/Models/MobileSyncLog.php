<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileSyncLog extends Model
{
    protected $fillable = [
        'quote_id',
        'opportunity_id',
        'reference_number',
        'response_data',
        'status',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'response_data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
