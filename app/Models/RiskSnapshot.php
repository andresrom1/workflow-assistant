<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskSnapshot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'customer_id',
        'marca',
        'modelo',
        'version',
        'year',
        'combustible',
        'uso',
        'codigo_postal',
        'dni',
        'edad_conductor',
        'coverage_preference',
    ];

    protected $casts = [
        'year' => 'integer',
        'edad_conductor' => 'date',
    ];

    // Relaciones "blandas" (pueden ser null si se borra el original)
    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withDefault();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withDefault();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Referencias opacas al catálogo de proveedores (token por `provider`).
     * Relación genérica: el dominio no conoce a Visred (ADR-001 / docs/v2/10 §8).
     */
    public function providerRefs(): HasMany
    {
        return $this->hasMany(RiskProviderRef::class);
    }
}
