<?php

namespace App\Models;

use App\Enums\RiskType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bien asegurado (auto, futuras: moto/hogar/AP/vida).
 *
 * STI + JSONB. Atributos type-specific viven en $metadata: para vehicle
 * { patente, marca, modelo, version, year, combustible, uso, codigo_postal }.
 *
 * @property RiskType $type
 * @property array<string, mixed> $metadata
 */
class Risk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'type',
        'label',
        'metadata',
    ];

    protected $casts = [
        'type' => RiskType::class,
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Poliza, $this> */
    public function polizas(): HasMany
    {
        return $this->hasMany(Poliza::class);
    }

    /** @return HasMany<SharedRisk, $this> */
    public function sharedRisks(): HasMany
    {
        return $this->hasMany(SharedRisk::class);
    }
}
