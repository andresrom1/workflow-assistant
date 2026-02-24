<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteProviderRef extends Model
{
    protected $fillable = [
        'quote_id',
        'external_quote_id',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
