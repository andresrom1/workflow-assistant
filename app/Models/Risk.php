<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exposición sobre un {@see InsurableAsset} (ACORD simplificado), de la que
 * cuelgan las pólizas. La identidad y los atributos estables del bien
 * (patente, marca, modelo, ...) viven en `asset->metadata`, no acá.
 *
 * Hoy el Risk es 1:1 transicional con su Asset: no hay datos de exposición
 * (uso, guarda, factores de suscripción) con consumidor todavía, así que
 * `metadata` queda vacía en altas nuevas. Filas migradas desde el modelo
 * viejo conservan una copia de la metadata original, que nadie lee más
 * (ver docs/v3/05-modelo-insurable-asset.md).
 *
 * @property AssetType $type
 * @property array<string, mixed> $metadata
 */
class Risk extends Model
{
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $with = ['asset'];

    protected $fillable = [
        'customer_id',
        'asset_id',
        'type',
        'label',
        'metadata',
    ];

    protected $casts = [
        'type' => AssetType::class,
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<InsurableAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(InsurableAsset::class, 'asset_id');
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
