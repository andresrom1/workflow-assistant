<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bien asegurable (ACORD simplificado): el objeto estable y re-identificable
 * (auto, inmueble, persona). Lleva la identidad (`natural_key`, derivada por
 * tipo) y los atributos del bien en `metadata` JSONB. La exposición vive en
 * {@see Risk} (hoy 1:1 transicional: no hay datos de exposición con consumidor
 * todavía).
 *
 * @property AssetType $type
 * @property ?string $natural_key
 * @property array<string, mixed> $metadata
 */
class InsurableAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['customer_id', 'type', 'label', 'natural_key', 'metadata'];

    protected $casts = [
        'type' => AssetType::class,
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // Única fuente de verdad de la clave, recalculada en todo alta/edición
        // (mismo patrón que Customer::documento_key).
        static::saving(function (InsurableAsset $asset): void {
            $asset->natural_key = $asset->type->naturalKey($asset->metadata ?? []);
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'asset_id');
    }
}
