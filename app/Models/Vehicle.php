<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'patente',
        'marca',
        'modelo',
        'version',
        'year',
        'combustible',
        'codigo_postal',
        'uso',
        'is_complete',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'year' => 'integer',
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

    // Verificar si el vehículo tiene todos los datos requeridos
    public function checkCompleteness(): void
    {
        $this->is_complete = !empty($this->marca) 
            && !empty($this->modelo) 
            && !empty($this->version) 
            && !empty($this->year) 
            && !empty($this->combustible)
            && !empty($this->codigo_postal);
        
        $this->save();
    }
}