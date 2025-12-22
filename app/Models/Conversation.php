<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_conversation_id',
        'customer_id',
        'channel',
        'status',
        'metadata',
        'ended_at',
        'external_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ✨ NUEVO: Relación many-to-many con vehicles
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    // Helper: Obtener el vehículo principal
    public function primaryVehicle()
    {
        return $this->vehicles()->wherePivot('is_primary', true)->first();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}