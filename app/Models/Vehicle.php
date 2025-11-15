<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'patente',
        'marca',
        'modelo',
        'version',
        'año',
        'combustible',
        'codigo_postal',
        'uso',
        'km_anuales',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'year' => 'integer',
        'km_anuales' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ✨ NUEVO: Relación many-to-many con conversations
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}